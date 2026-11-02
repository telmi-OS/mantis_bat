"""Telmi Files Sync: bidirectional S3 <-> Telmi Ghost Files API v2.

AWS Lambda handler: lambda_function.lambda_handler
Requires only the boto3/botocore included with the AWS Python Lambda runtime.
See README.md for IAM, configuration, sizing, limitations and first-run guide.

Non-negotiable policies:
- Telmi wins if both sides have diverged; preserve divergent S3 bytes in archive.
- S3 deletion: verify a remote-byte archive, journal, then file_delete.
- Telmi deletion: delete the tracked S3 object directly, without an archive.
- Moves retain remote IDs; ambiguous S3 content matches hard-stop.
- Zero Telmi files, incomplete remote listing, error or 409 => hard fail.
- file_replace with expected_sha256; upload is CREATE ONLY.
- State checkpoints in S3 after each successful change (conditional writes).
- Set Lambda reserved concurrency to ONE; no concurrent sync executions.
"""
from __future__ import annotations

import datetime as dt
import hashlib
import http.client
import json
import logging
import os
import re
import ssl
import tempfile
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
import uuid
from collections import deque
from dataclasses import dataclass

import boto3
from botocore.exceptions import ClientError, BotoCoreError

LOG = logging.getLogger("telmi_files_sync")
LOG.setLevel(logging.INFO)
SHA_RE = re.compile(r"^[0-9a-f]{64}$")


class SyncError(RuntimeError):
    pass


def stop(message):
    raise SyncError(message)


def sha_ok(value):
    return isinstance(value, str) and SHA_RE.fullmatch(value.lower()) is not None


def validate_component(name):
    if (not isinstance(name, str) or not name or name in (".", "..")
        or any(c in name for c in ("/", "\\", "\x00"))
        or any(ord(c) < 32 or ord(c) == 127 for c in name)):
        stop(f"unsafe file/folder name {name!r}")
    return name


def validate_rel(path):
    if not isinstance(path, str) or not path or path.startswith("/"):
        stop(f"unsafe relative path {path!r}")
    for component in path.split("/"):
        validate_component(component)
    return path


def norm_path(path):
    return unicodedata.normalize("NFC", path).casefold()


def check_collisions(files, folders):
    seen = {}
    for path, kind in [(p, "file") for p in files] + [(p, "folder") for p in folders if p]:
        canonical = norm_path(path)
        prev = seen.get(canonical)
        if prev is not None and prev != (path, kind):
            stop(f"path collision {prev} vs {(path, kind)}")
        seen[canonical] = (path, kind)
    for path in files:
        parts = path.split("/")
        for i in range(1, len(parts)):
            if parts[:i] and "/".join(parts[:i]) in files:
                stop(f"file/folder collision under {path!r}")


def json_body(response_bytes, label):
    try:
        value = json.loads(response_bytes)
    except (UnicodeDecodeError, ValueError) as exc:
        stop(f"{label}: invalid JSON: {exc}")
    if not isinstance(value, dict) or value.get("ok") is not True:
        stop(f"{label}: API response missing ok=true")
    data = value.get("data", {})
    if not isinstance(data, dict):
        stop(f"{label}: API response data is not an object")
    return data


def sha_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        while True:
            chunk = fh.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def stream_file(response, max_bytes, label):
    """Read any HTTP/S3 body into /tmp with bound, hash; caller removes file."""
    digest = hashlib.sha256()
    size = 0
    with tempfile.NamedTemporaryFile(prefix="telmi-sync-", delete=False) as out:
        path = out.name
        try:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                if size > max_bytes:
                    stop(f"{label}: exceeds MAX_FILE_BYTES={max_bytes}")
                digest.update(chunk)
                out.write(chunk)
            out.flush()
            return path, digest.hexdigest(), size
        except BaseException:
            out.close()
            os.unlink(path)
            raise


@dataclass
class Config:
    bucket: str
    prefix: str
    state_key: str
    archive_prefix: str
    base_url: str
    space_id: str
    secret_id: str
    limit: int
    max_depth: int
    max_files: int
    max_bytes: int
    timeout: int
    mode: str
    dry_run: bool
    verbose: bool
    deleted_prefix: str = "_telmi_sync/deleted/"
    max_deletes: int = 10
    max_delete_percent: int = 25

    @classmethod
    def load(cls, event):
        event = event or {}
        if not isinstance(event, dict):
            stop("event must be a JSON object")

        def env(name):
            value = os.getenv(name, "").strip()
            if not value:
                stop(f"missing environment setting {name}")
            return value

        def positive(name, default, low=1, high=10**10):
            try:
                v = int(os.getenv(name, str(default)))
            except ValueError:
                stop(f"{name} must be an integer")
            if not low <= v <= high:
                stop(f"{name} outside supported range {low}-{high}")
            return v

        def boolean(value):
            if isinstance(value, bool):
                return value
            if str(value).lower() in ("1", "true", "yes"):
                return True
            if str(value).lower() in ("0", "false", "no", ""):
                return False
            stop(f"invalid boolean {value!r}")

        prefix = env("S3_PREFIX").strip("/") + "/"
        if prefix == "/":
            stop("S3_PREFIX must be a nonempty sub-prefix, not the bucket root")
        state = os.getenv("STATE_KEY", "_telmi_sync/state.json").strip("/")
        archive = os.getenv("CONFLICT_PREFIX", "_telmi_sync/conflicts/").strip("/") + "/"
        if state.startswith(prefix) or archive.startswith(prefix):
            stop("STATE_KEY and CONFLICT_PREFIX MUST be outside S3_PREFIX")
        if state.startswith(archive) or archive.startswith(state + "/"):
            stop("state and conflict archive paths must not overlap")
        for item in (prefix, state, archive):
            if "//" in item or "\\" in item or any(c in (".", "..") for c in item.strip("/").split("/")):
                stop("unsafe S3 prefix/state/archive configuration")
        deleted = os.getenv("DELETED_PREFIX", "_telmi_sync/deleted/").strip("/") + "/"
        for other in (prefix, archive, state):
            if deleted.startswith(other) or other.startswith(deleted):
                stop("DELETED_PREFIX must not overlap data, state or conflict archive")
        validate_rel(deleted.rstrip("/"))
        url = env("TELMI_BASE_URL").rstrip("/")
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != "https" or not parsed.netloc or parsed.username or parsed.password or parsed.path not in ("", "/") or parsed.query or parsed.fragment:
            stop("TELMI_BASE_URL must be an HTTPS origin, e.g. https://tenant.example.com")
        mode = event.get("mode", os.getenv("RUN_MODE", "both"))
        if mode not in ("both", "pull", "push"):
            stop("mode must be both, pull or push")
        return cls(
            bucket=env("S3_BUCKET"), prefix=prefix, state_key=state,
            archive_prefix=archive, base_url=url, space_id=env("TELMI_SPACE_ID"),
            secret_id=env("TELMI_SECRET_ID"),
            limit=positive("TELMI_LIST_LIMIT", 500, 1, 500),
            max_depth=positive("MAX_FOLDER_DEPTH", 32, 1, 100),
            max_files=positive("MAX_FILES", 5000, 1, 100000),
            max_bytes=positive("MAX_FILE_BYTES", 50 * 1024 * 1024, 1, 1024 * 1024 * 1024),
            timeout=positive("HTTP_TIMEOUT", 90, 5, 600),
            mode=mode,
            dry_run=boolean(event.get("dry_run", os.getenv("DRY_RUN", "false"))),
            verbose=boolean(event.get("verbose", os.getenv("VERBOSE", "false"))),
            deleted_prefix=deleted,
            max_deletes=positive("MAX_LOCAL_DELETES_PER_RUN", 10, 1, 100000),
            max_delete_percent=positive("MAX_LOCAL_DELETE_PERCENT", 25, 1, 100),
        )


class GhostClient:
    def __init__(self, cfg, jwt):
        self.cfg = cfg
        if not jwt or any(c.isspace() for c in jwt):
            stop("Secrets Manager returned an empty/invalid JWT")
        self.jwt = jwt
        self.base_path = "/api/ghost/v2/files.php"
        p = urllib.parse.urlsplit(cfg.base_url)
        self.host = p.hostname
        self.port = p.port or 443

    def url(self, **query):
        return self.cfg.base_url + self.base_path + "?" + urllib.parse.urlencode(query)

    def request(self, action, params=None, post=None):
        params = params or {}
        url = self.url(action=action, **params)
        if post is None:
            request = urllib.request.Request(url, headers={"Authorization": "Bearer " + self.jwt,
                                                           "Accept": "application/json"}, method="GET")
        else:
            body = json.dumps(post, separators=(",", ":")).encode("utf-8")
            request = urllib.request.Request(url, body, headers={"Authorization": "Bearer " + self.jwt,
                                                                 "Content-Type": "application/json",
                                                                 "Accept": "application/json"}, method="POST")
        try:
            with urllib.request.urlopen(request, timeout=self.cfg.timeout) as response:
                if not 200 <= response.status < 300:
                    stop(f"HTTP {response.status} {action}")
                data = response.read(10 * 1024 * 1024 + 1)
                if len(data) > 10 * 1024 * 1024:
                    stop(f"API {action} JSON exceeded 10 MB")
        except urllib.error.HTTPError as exc:
            stop(f"HTTP {exc.code} Ghost API action={action}; hard stop (no blind mutation retries)")
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            stop(f"HTTP transport error action={action}: {type(exc).__name__}: {exc}")
        return json_body(data, f"Ghost API {action}")

    def list_folder(self, folder_id=None):
        params = {"space_id": self.cfg.space_id, "limit": self.cfg.limit}
        if folder_id:
            params["folder_id"] = folder_id
        data = self.request("list", params)
        folders = data.get("folders")
        files = data.get("files")
        if not isinstance(folders, list) or not isinstance(files, list):
            stop(f"list folder {folder_id or '/'}: missing folders[] or files[]")
        if len(folders) >= self.cfg.limit or len(files) >= self.cfg.limit:
            stop(f"list folder {folder_id or '/'}: ambiguous limit reached; API has no pagination")
        return folders, files

    def tree(self):
        folders = {"": None}
        files = {}
        ids = set()
        file_ids = set()
        queue = deque([(None, "", 0)])
        while queue:
            folder_id, parent, depth = queue.popleft()
            children, entries = self.list_folder(folder_id)
            LOG.info("LIST %s: %s folders, %s files", parent or "/", len(children), len(entries))
            for folder in children:
                if not isinstance(folder, dict):
                    stop("malformed folder record")
                fid, name = folder.get("folder_id"), validate_component(folder.get("name"))
                if not isinstance(fid, str) or not fid or fid in ids:
                    stop(f"duplicate/invalid folder ID at {parent!r}")
                ids.add(fid)
                path = parent + "/" + name if parent else name
                if path in folders:
                    stop(f"duplicate folder path {path}")
                if folder.get("parent_id") not in (None, "", folder_id or self.cfg.space_id):
                    stop("remote folder parent mismatch")
                if any(part.startswith(".telmi-") or part == ".DS_Store" or ".conflict-" in part for part in path.split("/")):
                    stop(f"reserved remote folder path {path}")
                if depth + 1 > self.cfg.max_depth:
                    stop(f"remote folder depth exceeded at {path}")
                folders[path] = fid
                queue.append((fid, path, depth + 1))
            for entry in entries:
                if not isinstance(entry, dict):
                    stop("malformed file record")
                name = validate_component(entry.get("name"))
                fid = entry.get("file_id") or entry.get("id")
                sha = entry.get("sha256")
                if not isinstance(fid, str) or not fid or fid in file_ids or not sha_ok(sha):
                    stop(f"invalid/duplicate file_id or SHA at {parent}/{name}")
                file_ids.add(fid)
                path = parent + "/" + name if parent else name
                if path in files:
                    stop(f"duplicate remote file path {path}")
                if entry.get("folder_id") not in (None, "", folder_id or self.cfg.space_id):
                    stop("remote file parent mismatch")
                if any(part.startswith(".telmi-") or part == ".DS_Store" or ".conflict-" in part for part in path.split("/")):
                    stop(f"reserved remote file path {path}")
                size = entry.get("size_bytes", entry.get("size"))
                if not isinstance(size, int) or size < 0 or size > self.cfg.max_bytes:
                    stop(f"remote file size missing/too large: {path}")
                files[path] = {"id": fid, "sha": sha.lower(), "size": size}
                if len(files) > self.cfg.max_files:
                    stop("remote file count exceeds MAX_FILES")
        if not files:
            stop("EMPTY TELMI SERVER: fewer than one file returned; hard abort")
        check_collisions(files, folders)
        return folders, files

    def download(self, remote):
        url = self.url(action="content", file_id=remote["id"])
        req = urllib.request.Request(url, headers={"Authorization": "Bearer " + self.jwt})
        try:
            with urllib.request.urlopen(req, timeout=self.cfg.timeout) as response:
                if response.status != 200:
                    stop(f"content download HTTP {response.status}")
                path, sha, size = stream_file(response, self.cfg.max_bytes, "Telmi download")
        except urllib.error.HTTPError as exc:
            stop(f"content download HTTP {exc.code}")
        except (urllib.error.URLError, OSError, TimeoutError) as exc:
            stop(f"content download transport error: {type(exc).__name__}: {exc}")
        if sha != remote["sha"] or size != remote["size"]:
            os.unlink(path)
            stop("Telmi content checksum/size changed since inventory; abort")
        return path

    def send_file(self, action, source, filename, fields):
        # Stream multipart to HTTPS without requests or a third-party library.
        boundary = "telmi-" + uuid.uuid4().hex
        head = bytearray()
        for key, value in fields.items():
            if not isinstance(value, str) or "\r" in value or "\n" in value:
                stop("unsafe multipart field")
            head += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n").encode()
        # PHP uses the multipart filename= parameter, so include the real name.
        # Escape quoted-string delimiters; RFC 5987 filename* is supplementary.
        quoted_name = filename.replace("\\", "\\\\").replace('"', '\\"')
        encoded_name = urllib.parse.quote(filename, safe="")
        head += (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{quoted_name}"; filename*=UTF-8\'\'{encoded_name}\r\n'
                 "Content-Type: application/octet-stream\r\n\r\n").encode("utf-8")
        tail = f"\r\n--{boundary}--\r\n".encode()
        length = len(head) + os.stat(source).st_size + len(tail)
        conn = http.client.HTTPSConnection(self.host, self.port, timeout=self.cfg.timeout, context=ssl.create_default_context())
        target = self.base_path + "?" + urllib.parse.urlencode({"action": action})
        try:
            conn.putrequest("POST", target)
            conn.putheader("Authorization", "Bearer " + self.jwt)
            conn.putheader("Content-Type", f"multipart/form-data; boundary={boundary}")
            conn.putheader("Content-Length", str(length))
            conn.putheader("Accept", "application/json")
            conn.endheaders()
            conn.send(head)
            with open(source, "rb") as stream:
                while chunk := stream.read(1024 * 1024):
                    conn.send(chunk)
            conn.send(tail)
            response = conn.getresponse()
            status = response.status
            body = response.read(1024 * 1024 + 1)
            if len(body) > 1024 * 1024:
                stop(f"Ghost {action} returned oversized response")
            if not 200 <= status < 300:
                stop(f"HTTP {status} Ghost {action}; server-wins hard abort; no automatic write retries")
            return json_body(body, f"Ghost API {action}")
        except (OSError, TimeoutError, http.client.HTTPException) as exc:
            stop(f"Ghost {action} transport error (write result may be unknown): {exc}")
        finally:
            conn.close()

    def upload(self, source, path, folder_id):
        fields = {"space_id": self.cfg.space_id}
        if folder_id:
            fields["folder_id"] = folder_id
        return self.send_file("upload", source, path.rsplit("/", 1)[-1], fields)

    def replace(self, source, path, remote):
        data = self.send_file("file_replace", source, path.rsplit("/", 1)[-1],
                              {"file_id": remote["id"], "expected_sha256": remote["sha"]})
        if data.get("file_id", remote["id"]) != remote["id"]:
            stop("file_replace changed file ID unexpectedly")
        if data.get("sha256") is not None and data["sha256"].lower() != sha_file(source):
            stop("file_replace response checksum mismatch")

    def create_folder(self, name, parent_id):
        body = {"space_id": self.cfg.space_id, "name": name}
        if parent_id:
            body["parent_id"] = parent_id
        data = self.request("folder_create", post=body)
        fid = data.get("folder_id")
        if not isinstance(fid, str) or not fid:
            stop("folder_create response missing folder_id")
        return fid

    def update_file(self, file_id, name, folder_id):
        return self.request("file_update", post={"file_id": file_id, "name": name,
                                                 "folder_id": folder_id or None})

    def update_folder(self, folder_id, name, parent_id):
        return self.request("folder_update", post={"folder_id": folder_id, "name": name,
                                                   "parent_id": parent_id or None})

    def delete_file(self, file_id):
        return self.request("file_delete", post={"file_id": file_id})


class S3Client:
    def __init__(self, cfg, s3):
        self.cfg = cfg
        self.s3 = s3
        for operation in ("PutObject", "DeleteObject"):
            if "IfMatch" not in s3.meta.service_model.operation_model(operation).input_shape.members:
                stop("AWS SDK lacks conditional writes/deletes; deploy a current boto3/botocore")

    def objects(self):
        objects, markers = {}, set()
        paginator = self.s3.get_paginator("list_objects_v2")
        for page in paginator.paginate(Bucket=self.cfg.bucket, Prefix=self.cfg.prefix):
            for item in page.get("Contents", []):
                key = item["Key"]
                if not key.startswith(self.cfg.prefix):
                    stop("S3 returned key outside prefix")
                rel = key[len(self.cfg.prefix):]
                if not rel:
                    continue
                if any(part.startswith(".telmi-") or part == ".DS_Store" or ".conflict-" in part for part in rel.rstrip("/").split("/")):
                    continue
                if key.endswith("/"):
                    validate_rel(rel.rstrip("/"))
                    if item["Size"] != 0:
                        stop(f"nonempty S3 folder marker {rel}")
                    markers.add(rel.rstrip("/"))
                    continue
                validate_rel(rel)
                if rel in objects:
                    stop(f"duplicate S3 key {rel}")
                if item["Size"] > self.cfg.max_bytes:
                    stop(f"S3 file exceeds MAX_FILE_BYTES: {rel}")
                objects[rel] = {"key": key, "etag": item["ETag"], "size": item["Size"]}
                if len(objects) > self.cfg.max_files:
                    stop("S3 object count exceeds MAX_FILES")
        folders = set(markers)
        for rel in list(objects) + list(markers):
            parts = rel.split("/")
            for n in range(1, len(parts)):
                folders.add("/".join(parts[:n]))
        check_collisions(objects, folders)
        return objects, folders

    def fetch(self, item):
        try:
            response = self.s3.get_object(Bucket=self.cfg.bucket, Key=item["key"], IfMatch=item["etag"])
            try:
                path, sha, size = stream_file(response["Body"], self.cfg.max_bytes, f"S3 {item['key']}")
            finally:
                response["Body"].close()
        except ClientError as exc:
            stop(f"S3 get_object failed for {item['key']}: {exc.response['Error']['Code']}")
        if size != item["size"]:
            os.unlink(path)
            stop(f"S3 object size changed during read: {item['key']}")
        return path, sha

    def put(self, key, source, old_etag=None):
        args = {"Bucket": self.cfg.bucket, "Key": key,
                "IfMatch": old_etag} if old_etag else {
                    "Bucket": self.cfg.bucket, "Key": key, "IfNoneMatch": "*"}
        try:
            with open(source, "rb") as stream:
                result = self.s3.put_object(**args, Body=stream)
        except ClientError as exc:
            stop(f"S3 conditional PutObject failed for {key}: {exc.response['Error']['Code']} (hard stop)")
        return result.get("ETag", "")

    def put_bytes(self, key, body, old_etag=None):
        kwargs = {"Bucket": self.cfg.bucket, "Key": key, "Body": body}
        kwargs["IfMatch" if old_etag else "IfNoneMatch"] = old_etag or "*"
        try:
            return self.s3.put_object(**kwargs).get("ETag", "")
        except ClientError as exc:
            stop(f"S3 conditional write failed for {key}: {exc.response['Error']['Code']}")

    def head(self, key):
        try:
            response = self.s3.head_object(Bucket=self.cfg.bucket, Key=key)
        except ClientError as exc:
            stop(f"S3 head_object failed: {exc.response['Error']['Code']}")
        size = response["ContentLength"]
        if size > self.cfg.max_bytes:
            stop(f"S3 post-write object exceeds MAX_FILE_BYTES: {key}")
        return {"key": key, "etag": response["ETag"], "size": size}

    def hash_inventory(self, objects):
        hashes = {}
        for rel, meta in objects.items():
            path, digest = self.fetch(meta)
            os.unlink(path)
            hashes[rel] = digest
        return hashes

    def delete(self, item):
        try:
            self.s3.delete_object(Bucket=self.cfg.bucket, Key=item["key"], IfMatch=item["etag"])
        except (ClientError, BotoCoreError) as exc:
            stop(f"S3 conditional DeleteObject failed for {item['key']}: {exc}")

    def archive_remote(self, rel, source, expected_sha):
        if sha_file(source) != expected_sha:
            stop("remote deletion archive source checksum mismatch")
        stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%d")
        key = self.cfg.deleted_prefix + stamp + "/" + uuid.uuid4().hex + "/" + rel
        self.put(key, source)
        snapshot = self.head(key)
        local, sha = self.fetch(snapshot)
        os.unlink(local)
        if sha != expected_sha:
            stop("remote deletion archive read-back checksum mismatch")
        LOG.info("ARCHIVE %s -> s3://%s/%s", rel, self.cfg.bucket, key)
        return key

    def archive(self, rel, s3item, expected_sha):
        path, digest = self.fetch(s3item)
        try:
            if digest != expected_sha:
                stop(f"S3 changed before conflict archive: {rel}")
            stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            key = self.cfg.archive_prefix + rel + ".conflict-" + stamp + "-" + uuid.uuid4().hex[:12]
            self.put(key, path)  # create-only; never overwrite an archive
            LOG.warning("CONFLICT preserved divergent S3 file at s3://%s/%s", self.cfg.bucket, key)
            return key
        finally:
            os.unlink(path)


class Sync:
    def __init__(self, cfg, s3, ghost, context):
        self.cfg, self.s3, self.ghost, self.context = cfg, s3, ghost, context
        self.state = {"version": 2, "bucket": cfg.bucket, "prefix": cfg.prefix,
                      "space_id": cfg.space_id, "base_url": cfg.base_url, "files": {},
                      "folders": {}, "pending_deletes": {}, "pending_moves": {}}
        self.migrated = False
        self.deferred = set()
        self.force_restore = set()
        self.state_etag = None
        self.remote_folders = {"": None}
        self.remote = {}
        self.s3_files = {}
        self.s3_folders = set()
        self.s3_hashes = {}
        self.count = {"downloads": 0, "uploads": 0, "replacements": 0,
                      "conflicts": 0, "folders": 0, "skipped": 0,
                      "moves": 0, "deletes": 0, "s3_deletes": 0}

    def load_state(self):
        try:
            obj = self.s3.s3.get_object(Bucket=self.cfg.bucket, Key=self.cfg.state_key)
        except ClientError as exc:
            if exc.response["Error"]["Code"] in ("NoSuchKey", "404"):
                return
            stop(f"state read error: {exc.response['Error']['Code']}")
        try:
            data = obj["Body"].read(20 * 1024 * 1024 + 1)
            if len(data) > 20 * 1024 * 1024:
                stop("state file too large")
            raw = json.loads(data)
        except (ValueError, UnicodeDecodeError):
            stop("invalid state JSON; do not delete or reset it casually")
        finally:
            obj["Body"].close()
        if not isinstance(raw, dict) or raw.get("version") not in (1, 2) or any(raw.get(k) != getattr(self.cfg, k if k != "space_id" else "space_id") for k in ("bucket", "prefix", "space_id")) or not isinstance(raw.get("files"), dict):
            stop("state scope/schema invalid; refusing to merge sync history")
        for rel, record in raw["files"].items():
            validate_rel(rel)
            if not isinstance(record, dict) or not isinstance(record.get("remote_missing"), bool):
                stop(f"invalid state record for {rel!r}")
            for field in ("remote_sha", "s3_sha"):
                if record.get(field) is not None and not sha_ok(record[field]):
                    stop(f"invalid state hash {rel!r}: {field}")
            if record.get("remote_id") is not None and not isinstance(record.get("remote_id"), str):
                stop(f"invalid remote ID in state {rel!r}")
        self.migrated = raw["version"] == 1
        if self.migrated:
            LOG.info("MIGRATE state v1 -> v2; S3-to-Telmi deletions disabled for migration run")
            raw.update(version=2, base_url=self.cfg.base_url, folders={}, pending_deletes={}, pending_moves={})
        if raw.get("base_url") != self.cfg.base_url:
            stop("state belongs to another Telmi server")
        for section in ("folders", "pending_deletes", "pending_moves"):
            if not isinstance(raw.get(section), dict):
                stop(f"invalid state {section}")
        for fid, rec in raw["folders"].items():
            if not isinstance(fid, str) or not fid or not isinstance(rec, dict):
                stop("invalid folder state")
            validate_rel(rec.get("path"))
        for fid, rec in raw["pending_deletes"].items():
            if not isinstance(rec, dict) or not sha_ok(rec.get("sha")):
                stop("invalid pending delete")
            validate_rel(rec.get("path"))
            if not isinstance(rec.get("archive"), str) or not rec["archive"].startswith(self.cfg.deleted_prefix):
                stop("invalid pending delete archive")
            validate_rel(rec["archive"])
        for fid, rec in raw["pending_moves"].items():
            if not isinstance(rec, dict) or not sha_ok(rec.get("sha")) or not isinstance(rec.get("baseline"), dict):
                stop("invalid pending S3 move")
            validate_rel(rec.get("src")); validate_rel(rec.get("dest"))
        self.state = raw
        self.state_etag = obj["ETag"]

    def checkpoint(self, rel, remote=None, s3sha=None, remote_missing=False):
        self.state["files"][rel] = {"remote_sha": remote["sha"] if remote else None,
                                    "remote_id": remote["id"] if remote else None,
                                    "s3_sha": s3sha, "remote_missing": bool(remote_missing)}
        self.save_state()

    def save_state(self):
        if self.cfg.dry_run:
            return
        encoded = (json.dumps(self.state, sort_keys=True, separators=(",", ":")) + "\n").encode()
        self.state_etag = self.s3.put_bytes(self.cfg.state_key, encoded, self.state_etag)

    def ensure_folders(self, rel):
        parent = rel.rsplit("/", 1)[0] if "/" in rel else ""
        if not parent:
            return None
        cur = ""
        for component in parent.split("/"):
            nxt = cur + "/" + component if cur else component
            if nxt not in self.remote_folders:
                if self.cfg.dry_run:
                    LOG.info("MKDIR-REMOTE %s/ (dry-run)", nxt)
                    self.remote_folders[nxt] = "DRYRUN:" + nxt
                else:
                    # Re-read current parent before a create-only mutation.
                    siblings, _ = self.ghost.list_folder(self.remote_folders[cur])
                    if any(x.get("name") == component for x in siblings):
                        stop(f"remote folder appeared concurrently: {nxt}")
                    fid = self.ghost.create_folder(component, self.remote_folders[cur])
                    siblings, _ = self.ghost.list_folder(self.remote_folders[cur])
                    if len([x for x in siblings if x.get("name") == component and x.get("folder_id") == fid]) != 1:
                        stop(f"folder creation could not be verified: {nxt}")
                    self.remote_folders[nxt] = fid
                    self.count["folders"] += 1
                    LOG.info("MKDIR-REMOTE %s/", nxt)
            cur = nxt
        return self.remote_folders[parent]

    def marker(self, folder):
        key = self.cfg.prefix + folder + "/"
        if folder in self.s3_folders:
            # Some prefixes exist only implicitly. No marker required.
            return
        if self.cfg.dry_run:
            LOG.info("MKDIR-S3 %s/ (dry-run)", folder)
        else:
            self.s3.put_bytes(key, b"")
        self.s3_folders.add(folder)

    def preflight(self):
        self.load_state()
        self.remote_folders, self.remote = self.ghost.tree()
        self.s3_files, self.s3_folders = self.s3.objects()
        LOG.info("INVENTORY remote=%s S3=%s remote_folders=%s S3_folders=%s",
                 len(self.remote), len(self.s3_files), len(self.remote_folders)-1, len(self.s3_folders))
        self.s3_hashes = self.s3.hash_inventory(self.s3_files)
        # Reject file vs folder conflicts across systems BEFORE a mutation.
        check_collisions(set(self.remote) | set(self.s3_files),
                         set(self.remote_folders) | set(self.s3_folders))
        for rel, record in self.state["files"].items():
            validate_rel(rel)
            if (self.migrated and not record.get("remote_missing") and rel in self.remote
                    and rel not in self.s3_files):
                stop(f"legacy migration blocked: previously synced S3 file missing: {rel}")

    def check_remaining(self):
        if self.context is not None and hasattr(self.context, "get_remaining_time_in_millis"):
            if self.context.get_remaining_time_in_millis() < 60000:
                stop("Lambda time remaining below 60s; hard stop before next action")

    def action(self, rel):
        cfg = self.cfg
        r = self.remote.get(rel)
        s = self.s3_files.get(rel)
        sh = self.s3_hashes.get(rel)
        base = self.state["files"].get(rel)
        if r is None:
            if s is None:
                return
            if base is not None:
                if base.get("archive"):
                    self.count["skipped"] += 1
                    return  # local-deletion tombstone: do not resurrect archived paths
                if cfg.mode == "push":
                    self.count["skipped"] += 1
                    return
                LOG.info("DELETE-S3 %s (server deleted; no archive)%s", rel,
                         " (dry-run)" if cfg.dry_run else "")
                if not cfg.dry_run:
                    self.s3.delete(s)
                    self.checkpoint(rel, None, None, remote_missing=True)
                self.count["s3_deletes"] += 1
                return
            if cfg.mode == "pull":
                LOG.info("SKIP S3-only %s (pull mode)", rel)
                return
            LOG.info("UPLOAD %s%s", rel, " (dry-run)" if cfg.dry_run else "")
            if cfg.dry_run:
                self.count["uploads"] += 1
                return
            folder_id = self.ensure_folders(rel)
            # Recheck destination just before a create-only upload.
            _, entries = self.ghost.list_folder(folder_id)
            if any(x.get("name") == rel.rsplit("/", 1)[-1] for x in entries):
                stop(f"remote file appeared before create-only upload: {rel}")
            source, sha = self.s3.fetch(s)
            try:
                if sha != sh:
                    stop(f"S3 source changed before upload {rel}")
                self.ghost.upload(source, rel, folder_id)
            finally:
                os.unlink(source)
            remote_new = self.verify_remote(rel, expected_sha=sh, expected_id=None)
            self.remote[rel] = remote_new
            self.checkpoint(rel, remote_new, sh)
            self.count["uploads"] += 1
            return

        if s is None:
            if (base and not base.get("remote_missing") and not self.migrated
                    and base.get("remote_id") == r["id"] and base.get("remote_sha") == r["sha"]
                    and cfg.mode != "pull" and rel not in self.force_restore):
                self.delete_remote(rel, r)
                return
            if cfg.mode == "push":
                LOG.info("SKIP remote-only %s (push mode)", rel)
                return
            LOG.info("DOWNLOAD %s%s", rel, " (dry-run)" if cfg.dry_run else "")
            if cfg.dry_run:
                self.count["downloads"] += 1
                return
            self.pull(rel, r, None, False)
            return

        if r["sha"] == sh:
            if base is None or (base.get("remote_sha"), base.get("s3_sha"), base.get("remote_id")) != (r["sha"], sh, r["id"]) or base.get("remote_missing"):
                self.checkpoint(rel, r, sh)
            return

        # State absent => conservative server-wins (neither side is trusted history).
        remote_changed = base is None or base.get("remote_missing") or r["sha"] != base.get("remote_sha") or r["id"] != base.get("remote_id")
        s3_changed = base is None or sh != base.get("s3_sha")
        if remote_changed:
            if cfg.mode == "push":
                LOG.info("SKIP server-changed %s (push mode)", rel)
                return
            conflict = bool(s3_changed)
            LOG.info("%s %s%s", "CONFLICT-SERVER-WINS" if conflict else "DOWNLOAD", rel,
                     " (dry-run)" if cfg.dry_run else "")
            if cfg.dry_run:
                self.count["conflicts" if conflict else "downloads"] += 1
                return
            self.pull(rel, r, s, conflict, sh)
            return
        if s3_changed:
            if cfg.mode == "pull":
                LOG.info("SKIP S3-changed %s (pull mode)", rel)
                return
            LOG.info("REPLACE %s%s", rel, " (dry-run)" if cfg.dry_run else "")
            if cfg.dry_run:
                self.count["replacements"] += 1
                return
            source, sha = self.s3.fetch(s)
            try:
                if sha != sh:
                    stop("S3 source changed before file_replace")
                self.verify_remote(rel, r["sha"], r["id"])
                self.ghost.replace(source, rel, r)
            finally:
                os.unlink(source)
            new_remote = self.verify_remote(rel, expected_sha=sh, expected_id=r["id"])
            self.remote[rel] = new_remote
            self.checkpoint(rel, new_remote, sh)
            self.count["replacements"] += 1
            return
        stop(f"state diverged unexpectedly at {rel}; refusing to guess")

    def verify_remote(self, rel, expected_sha, expected_id=None):
        parent = rel.rsplit("/", 1)[0] if "/" in rel else ""
        folder_id = self.remote_folders.get(parent)
        _, records = self.ghost.list_folder(folder_id)
        name = rel.rsplit("/", 1)[-1]
        matches = [x for x in records if x.get("name") == name]
        if len(matches) != 1 or not sha_ok(matches[0].get("sha256")):
            stop(f"remote post-write verification failed for {rel}")
        rec = matches[0]
        rid = rec.get("file_id") or rec.get("id")
        if not isinstance(rid, str) or (expected_id and rid != expected_id) or rec["sha256"].lower() != expected_sha:
            stop(f"remote post-write ID/checksum mismatch at {rel}")
        size = rec.get("size_bytes", rec.get("size"))
        if not isinstance(size, int) or size < 0:
            stop(f"remote post-write size missing at {rel}")
        return {"id": rid, "sha": expected_sha, "size": size}

    def pull(self, rel, remote, s3item, conflict, old_sha=None):
        # Download/verify Telmi bytes before archiving/writing S3.
        local = self.ghost.download(remote)
        try:
            self.verify_remote(rel, remote["sha"], remote["id"])
            if conflict:
                self.s3.archive(rel, s3item, old_sha)
            self.s3.put(self.cfg.prefix + rel, local, s3item["etag"] if s3item else None)
            # Verify the full S3 bytes after the conditional write, before
            # recording the file as synchronized.
            snapshot = self.s3.head(self.cfg.prefix + rel)
            verification, actual_sha = self.s3.fetch(snapshot)
            os.unlink(verification)
            if actual_sha != remote["sha"]:
                stop(f"S3 post-write checksum mismatch at {rel}; state not advanced")
            self.checkpoint(rel, remote, remote["sha"])
            if conflict:
                self.count["conflicts"] += 1
            else:
                self.count["downloads"] += 1
            LOG.info("DOWNLOAD %s (server wins%s)", rel, ", conflict archived" if conflict else "")
        finally:
            os.unlink(local)

    def remote_by_id(self):
        return {rec["id"]: (path, rec) for path, rec in self.remote.items()}

    def rewrite_paths(self, src, dest):
        for path in list(self.state["files"]):
            if path.startswith(src + "/"):
                new = dest + path[len(src):]
                if new in self.state["files"]:
                    stop("folder move state path collision")
                self.state["files"][new] = self.state["files"].pop(path)
        for record in self.state["folders"].values():
            if record["path"] == src or record["path"].startswith(src + "/"):
                record["path"] = dest + record["path"][len(src):]

    def reconcile_folders(self):
        # S3 has no inode: infer only complete, unique, unchanged subtree moves.
        current = {fid: path for path, fid in self.remote_folders.items() if path}
        for fid, old in sorted(list(self.state["folders"].items()), key=lambda pair: pair[1]["path"].count("/")):
            src = old["path"]
            rp = current.get(fid)
            if rp is None or rp != src or src in self.s3_folders:
                continue  # server moves are reconciled by file ID below
            baseline = {p[len(src)+1:]: rec.get("s3_sha") for p, rec in self.state["files"].items()
                        if p.startswith(src + "/") and not rec.get("remote_missing")}
            if not baseline:
                continue  # empty S3 prefixes cannot establish move identity
            matches = []
            for candidate in self.s3_folders - set(self.remote_folders):
                contents = {p[len(candidate)+1:]: sha for p, sha in self.s3_hashes.items()
                            if p.startswith(candidate + "/")}
                if contents == baseline:
                    matches.append(candidate)
            if len(matches) > 1:
                stop(f"ambiguous S3 folder move {src}: {matches}")
            if not matches:
                continue
            for other_fid, other in self.state["folders"].items():
                other_path = other["path"]
                if other_fid == fid or other_path in self.s3_folders:
                    continue
                other_contents = {p[len(other_path)+1:]: rec.get("s3_sha")
                                  for p, rec in self.state["files"].items()
                                  if p.startswith(other_path + "/") and not rec.get("remote_missing")}
                if other_contents == baseline:
                    stop("ambiguous S3 folder move: multiple source folders match")
            dest = matches[0]
            if dest.startswith(src + "/"):
                stop("folder cannot move into itself")
            LOG.info("%s %s -> %s%s", "MOVE-S3-FOLDER" if self.cfg.mode == "pull" else "MOVE-REMOTE-FOLDER",
                     src, dest, " (dry-run)" if self.cfg.dry_run else "")
            if self.cfg.dry_run:
                return True
            if self.cfg.mode == "pull":
                for suffix in baseline:
                    self.move_s3(dest + "/" + suffix, src + "/" + suffix,
                                 self.state["files"][src + "/" + suffix], state_src=src + "/" + suffix)
            else:
                parent = self.ensure_folders(dest)
                folders, files = self.ghost.tree()
                if folders.get(src) != fid or dest in folders:
                    stop("remote folder changed before folder_update")
                self.ghost.update_folder(fid, dest.rsplit("/", 1)[-1], parent)
                self.remote_folders, self.remote = self.ghost.tree()
                if self.remote_folders.get(dest) != fid:
                    stop("folder_update verification failed")
                old_ids = {rec["remote_id"] for p, rec in self.state["files"].items()
                           if p.startswith(src + "/") and not rec.get("remote_missing")}
                if not old_ids.issubset(self.remote_by_id()):
                    stop("folder_update lost child file IDs")
                self.rewrite_paths(src, dest)
                self.save_state()
                self.count["moves"] += 1
                current = {fid: path for path, fid in self.remote_folders.items() if path}
        return False

    def local_match(self, src, base):
        if src in self.s3_files:
            return src
        # Only previously untracked keys can be new local move destinations.
        matches = [p for p, sha in self.s3_hashes.items()
                   if p not in self.state["files"] and sha == base.get("s3_sha")]
        sources = [p for p, rec in self.state["files"].items()
                   if p not in self.s3_files and not rec.get("remote_missing")
                   and rec.get("s3_sha") == base.get("s3_sha")]
        if len(matches) > 1 or (matches and len(sources) > 1):
            stop(f"ambiguous S3 file move {src}: sources={sources}, destinations={matches}")
        return matches[0] if matches else None

    def reconcile_files(self):
        remote_ids = self.remote_by_id()
        for src, base in list(self.state["files"].items()):
            if base.get("remote_missing"):
                continue
            found = remote_ids.get(base.get("remote_id"))
            lp = self.local_match(src, base)
            if found is None:
                if self.cfg.mode != "push":
                    if lp is not None:
                        LOG.info("DELETE-S3 %s (server deleted; no archive)%s", lp,
                                 " (dry-run)" if self.cfg.dry_run else "")
                        if not self.cfg.dry_run:
                            self.s3.delete(self.s3_files[lp])
                        self.s3_files.pop(lp, None); self.s3_hashes.pop(lp, None)
                        self.count["s3_deletes"] += 1
                    self.state["files"].pop(src, None)
                    self.save_state()
                else:
                    self.deferred.update(p for p in (src, lp) if p)
                continue
            rp, remote = found
            if rp != src:
                if lp is None:
                    self.force_restore.add(rp)
                if self.cfg.mode == "push":
                    self.deferred.update(p for p in (src, lp, rp) if p)
                    continue
                if lp is not None and lp != rp:
                    self.move_s3(lp, rp, base, state_src=src)
                else:
                    self.state["files"].pop(src, None)
                    self.state["files"][rp] = base
                    self.save_state()
                continue
            if lp is None or lp == src:
                continue
            if self.cfg.mode == "pull" or remote["sha"] != base.get("remote_sha"):
                if self.cfg.mode != "push":
                    self.move_s3(lp, src, base, state_src=src)
                else:
                    self.deferred.update((lp, src))
                continue
            if lp in self.remote:
                stop(f"S3 move destination occupied remotely: {lp}")
            LOG.info("MOVE-REMOTE-FILE %s -> %s%s", src, lp, " (dry-run)" if self.cfg.dry_run else "")
            if not self.cfg.dry_run:
                parent = self.ensure_folders(lp)
                self.verify_remote(src, remote["sha"], remote["id"])
                _, siblings = self.ghost.list_folder(parent)
                if any(x.get("name") == lp.rsplit("/", 1)[-1] for x in siblings):
                    stop("move destination appeared concurrently")
                self.ghost.update_file(remote["id"], lp.rsplit("/", 1)[-1], parent)
                self.verify_remote(lp, remote["sha"], remote["id"])
            self.remote[lp] = self.remote.pop(src)
            self.state["files"][lp] = self.state["files"].pop(src)
            self.save_state()
            self.count["moves"] += 1

    def move_s3(self, src, dest, baseline, state_src=None):
        if dest in self.s3_files:
            stop(f"S3 move destination occupied: {dest}")
        if dest in self.state["files"] and dest != (state_src or src):
            stop(f"S3 move destination overlaps tracked file: {dest}")
        sha = self.s3_hashes[src]
        fid = baseline.get("remote_id")
        journal = {"src": src, "dest": dest, "state_src": state_src or src,
                   "sha": sha, "baseline": dict(baseline)}
        LOG.info("MOVE-S3 %s -> %s%s", src, dest, " (dry-run)" if self.cfg.dry_run else "")
        if self.cfg.dry_run:
            item = dict(self.s3_files.pop(src), key=self.cfg.prefix + dest)
            self.s3_files[dest] = item
            self.s3_hashes[dest] = self.s3_hashes.pop(src)
            self.state["files"].pop(state_src or src, None)
            self.state["files"][dest] = dict(baseline)
        else:
            self.state["pending_moves"][fid] = journal
            self.save_state()
            self.complete_move(fid, journal)
        self.count["moves"] += 1

    def complete_move(self, fid, journal):
        src, dest, sha = journal["src"], journal["dest"], journal["sha"]
        source = self.s3_files.get(src)
        target = self.s3_files.get(dest)
        if source and self.s3_hashes[src] != sha:
            stop("pending S3 move source changed; manual resolution required")
        if target and self.s3_hashes[dest] != sha:
            stop("pending S3 move destination changed; manual resolution required")
        if not target:
            if not source:
                stop("pending S3 move lost both source and destination")
            local, actual = self.s3.fetch(source)
            try:
                if actual != sha:
                    stop("S3 move source changed")
                self.s3.put(self.cfg.prefix + dest, local)
            finally:
                os.unlink(local)
            target = self.s3.head(self.cfg.prefix + dest)
            check, actual = self.s3.fetch(target)
            os.unlink(check)
            if actual != sha:
                stop("S3 move copy verification failed")
            self.s3_files[dest] = target; self.s3_hashes[dest] = sha
        if source:
            self.s3.delete(source)
            self.s3_files.pop(src, None); self.s3_hashes.pop(src, None)
        self.state["files"].pop(journal["state_src"], None)
        self.state["files"][dest] = journal["baseline"]
        self.state["pending_moves"].pop(fid, None)
        self.save_state()

    def recover_moves(self):
        for fid, journal in list(self.state["pending_moves"].items()):
            if self.cfg.dry_run or self.cfg.mode == "push":
                stop("pending S3 move requires a real default/pull run before continuing")
            self.complete_move(fid, journal)
            LOG.info("RECOVER-S3-MOVE %s -> %s", journal["src"], journal["dest"])

    def is_delete_candidate(self, rel):
        base = self.state["files"].get(rel)
        remote = self.remote.get(rel)
        return bool(remote and rel not in self.s3_files and base and not base.get("remote_missing")
                    and base.get("remote_id") == remote["id"] and base.get("remote_sha") == remote["sha"]
                    and self.cfg.mode != "pull" and not self.migrated and rel not in self.deferred
                    and rel not in self.force_restore)

    def guard_deletes(self):
        candidates = [rel for rel in self.remote if self.is_delete_candidate(rel)]
        limit = min(self.cfg.max_deletes, max(1, len(self.remote) * self.cfg.max_delete_percent // 100))
        if len(candidates) > limit or (candidates and len(candidates) >= len(self.remote)):
            stop("S3 deletion guard exceeded or would delete final remote file")

    def delete_remote(self, rel, remote):
        LOG.info("ARCHIVE+DELETE-REMOTE %s%s", rel, " (dry-run)" if self.cfg.dry_run else "")
        if self.cfg.dry_run:
            self.count["deletes"] += 1
            return
        folders, fresh = self.ghost.tree()
        if len(fresh) <= 1 or fresh.get(rel) != remote:
            stop("remote changed before deletion or deletion would empty space")
        objects, _ = self.s3.objects()
        if rel in objects:
            stop("S3 object reappeared before deletion")
        self.verify_remote(rel, remote["sha"], remote["id"])
        local = self.ghost.download(remote)
        try:
            archive = self.s3.archive_remote(rel, local, remote["sha"])
        finally:
            os.unlink(local)
        self.verify_remote(rel, remote["sha"], remote["id"])
        if rel in self.s3.objects()[0]:
            stop("S3 object reappeared during archive")
        self.state["pending_deletes"][remote["id"]] = {"path": rel, "sha": remote["sha"], "archive": archive}
        self.save_state()
        self.ghost.delete_file(remote["id"])
        parent = rel.rsplit("/", 1)[0] if "/" in rel else ""
        _, entries = self.ghost.list_folder(self.remote_folders[parent])
        if any(x.get("file_id") == remote["id"] for x in entries):
            stop("remote delete verification failed")
        self.finish_delete(remote["id"])
        self.remote.pop(rel, None)
        self.count["deletes"] += 1

    def finish_delete(self, fid):
        pending = self.state["pending_deletes"].pop(fid)
        self.state["files"][pending["path"]] = {
            "remote_sha": None, "remote_id": fid, "s3_sha": None,
            "remote_missing": True, "archive": pending["archive"]}
        self.save_state()

    def recover_deletes(self):
        ids = self.remote_by_id()
        for fid, pending in list(self.state["pending_deletes"].items()):
            check, sha = self.s3.fetch(self.s3.head(pending["archive"]))
            os.unlink(check)
            if sha != pending["sha"]:
                stop("pending delete archive checksum mismatch")
            if fid in ids:
                stop("pending delete still exists on Telmi; archive safe; manual resolution required")
            if not self.cfg.dry_run:
                self.finish_delete(fid)
            LOG.info("RECOVER-COMMITTED-DELETE %s", pending["path"])

    def run(self):
        self.deferred = set()
        self.force_restore = set()
        self.preflight()  # errors / empty inventory stop BEFORE mutations
        self.recover_deletes()
        self.recover_moves()
        if self.reconcile_folders():
            return {"ok": True, "dry_run": True, "folder_moves_only": True, **self.count}
        self.reconcile_files()
        self.guard_deletes()
        # Do not recreate historical remote-deleted folder prefixes just because
        # empty S3 folder markers remain. New folders are created for new files.
        if self.cfg.mode != "push":
            for folder in sorted((p for p in self.remote_folders if p), key=lambda p:(p.count("/"),p)):
                if folder not in self.s3_folders:
                    self.check_remaining()
                    self.marker(folder)
        paths = sorted(set(self.remote) | set(self.s3_files))
        # Transfers/moves/uploads complete before archive-first server deletions.
        paths.sort(key=lambda rel: self.is_delete_candidate(rel))
        LOG.info("PLAN %s paths; mode=%s dry_run=%s", len(paths), self.cfg.mode, self.cfg.dry_run)
        for rel in paths:
            if rel in self.deferred:
                continue
            self.check_remaining()
            self.action(rel)
        self.state["folders"] = {fid: {"path": path} for path, fid in self.remote_folders.items() if path}
        self.save_state()
        LOG.info("DONE %s", json.dumps(self.count, sort_keys=True))
        return {"ok": True, "mode": self.cfg.mode, "dry_run": self.cfg.dry_run,
                "remote_files": len(self.remote), "s3_files": len(self.s3_files), **self.count}


def secret_jwt(secret_id):
    try:
        response = boto3.client("secretsmanager").get_secret_value(SecretId=secret_id)
    except (ClientError, BotoCoreError) as exc:
        stop(f"Secrets Manager get_secret_value failed: {type(exc).__name__}")
    raw = response.get("SecretString")
    if not isinstance(raw, str) or not raw.strip():
        stop("SecretString missing (binary secrets not supported)")
    try:
        decoded = json.loads(raw)
    except ValueError:
        decoded = raw.strip()
    if isinstance(decoded, str):
        return decoded.strip()
    if isinstance(decoded, dict):
        for key in ("jwt", "TELMI_GHOST_JWT", "token"):
            if isinstance(decoded.get(key), str) and decoded[key].strip():
                return decoded[key].strip()
    stop("Secret must be a JWT string or JSON with jwt/TELMI_GHOST_JWT/token")


def lambda_handler(event, context):
    try:
        cfg = Config.load(event)
        LOG.info("START bucket=%s prefix=%s mode=%s dry_run=%s space=%s",
                 cfg.bucket, cfg.prefix, cfg.mode, cfg.dry_run, cfg.space_id)
        client = GhostClient(cfg, secret_jwt(cfg.secret_id))
        return Sync(cfg, S3Client(cfg, boto3.client("s3")), client, context).run()
    except Exception as exc:
        LOG.error("ERROR HARD STOP: %s", exc)
        raise
