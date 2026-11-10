#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

readonly SERVICE_USER="ghostshell"
readonly APP_DIR="/opt/shellapi"
readonly ENV_FILE="${APP_DIR}/.env"
readonly API_FILE="${APP_DIR}/shell_api.py"
readonly UNIT_FILE="/etc/systemd/system/shell-api.service"
readonly ACTIVATION_DIR="/etc/ghost-shell"
readonly ACTIVATION_FILE="${ACTIVATION_DIR}/activation.txt"
readonly ACTIVATION_PHRASE="I have understood the risk and therefore explicitly grant access to this VM to my Ghost via Ghost Shell."

die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }
warn() { printf '\nWARNING: %s\n' "$*" >&2; }

[[ "${EUID}" -eq 0 ]] || die "Run this script as root: sudo bash $0"
[[ -r /etc/os-release ]] || die "Cannot identify the operating system."
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == "24.04" ]] || \
    die "This installer supports Ubuntu 24.04 LTS only (detected: ${PRETTY_NAME:-unknown})."
command -v systemctl >/dev/null || die "systemd is required."

if [[ -e "${API_FILE}" || -e "${UNIT_FILE}" ]]; then
    die "A shell-api installation already exists. Refusing to overwrite it."
fi

printf '%s\n' \
  'Ghost Shell — Ubuntu 24.04 installer' \
  'This installs a remotely accessible shell API with root-capable sudo.' \
  'You will need a DNS name pointing to this VM and inbound TCP ports 80/443 open.'
printf '\n'

read -r -p "Public API hostname (for example shell.example.com): " DOMAIN
read -r -p "Allowed requester IP address(es)/CIDR (comma-separated): " ALLOWED_INPUT

cat <<'NOTICE'

SECURITY WARNING
This API accepts shell commands from the allowlisted IP when the bearer token
is supplied. The service account also has passwordless sudo for Ghost commands.
Keep the token private, use a dedicated VM, and restrict its cloud firewall.
NOTICE
read -r -p "Type INSTALL to continue: " CONFIRM
[[ "${CONFIRM}" == "INSTALL" ]] || die "Installation cancelled."

if [[ -f /etc/caddy/Caddyfile ]]; then
    cat <<'CADDY_WARNING'

WARNING: An existing /etc/caddy/Caddyfile was found.
Proceeding will back it up and replace it with the Ghost Shell configuration.
Other websites or services configured in that file may stop working.
CADDY_WARNING
    read -r -p "Replace the existing Caddyfile and continue? Type REPLACE to proceed: " REPLACE_CADDY
    [[ "${REPLACE_CADDY}" == "REPLACE" ]] || die "Installation cancelled; the existing Caddyfile was not changed."
fi

info "Installing OS packages and the official Caddy stable package"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl debian-archive-keyring debian-keyring \
    gnupg openssl python3 python3-venv python3-pip apt-transport-https

while ! python3 - "$DOMAIN" <<'PY'
import re, sys
value = sys.argv[1].rstrip(".")
labels = value.split(".")
ok = len(value) <= 253 and len(labels) >= 2 and all(
    1 <= len(label) <= 63
    and re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?", label)
    for label in labels
)
sys.exit(0 if ok else 1)
PY
do
    read -r -p "Invalid hostname. Enter a public DNS name such as shell.example.com: " DOMAIN
done
DOMAIN="${DOMAIN%.}"

while ! python3 - "$ALLOWED_INPUT" <<'PY'
import ipaddress, sys
entries = [item.strip() for item in sys.argv[1].split(",") if item.strip()]
if not entries:
    raise SystemExit(1)
try:
    for item in entries:
        ipaddress.ip_network(item, strict=False)
except ValueError:
    raise SystemExit(1)
raise SystemExit(0)
PY
do
    read -r -p "Invalid IP list. Enter valid addresses/CIDRs separated by commas: " ALLOWED_INPUT
done
ALLOWED_CSV="$(python3 - "$ALLOWED_INPUT" <<'PY'
import ipaddress, sys
print(",".join(str(ipaddress.ip_network(x.strip(), strict=False)) for x in sys.argv[1].split(",") if x.strip()))
PY
)"
ALLOWED_CADDY="${ALLOWED_CSV//,/ }"

install -d -m 0755 /usr/share/keyrings
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    -o /etc/apt/sources.list.d/caddy-stable.list
chmod 0644 /usr/share/keyrings/caddy-stable-archive-keyring.gpg \
    /etc/apt/sources.list.d/caddy-stable.list
apt-get update
apt-get install -y caddy

info "Generating a fresh 256-bit bearer token"
TOKEN="$(openssl rand -hex 32)"
[[ "${#TOKEN}" -eq 64 ]] || die "Could not generate a 256-bit bearer token."

info "Creating the restricted service account and application directory"
if ! id "${SERVICE_USER}" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "${SERVICE_USER}"
fi
install -d -o "${SERVICE_USER}" -g "${SERVICE_USER}" -m 0750 "${APP_DIR}"
install -d -o "${SERVICE_USER}" -g "${SERVICE_USER}" -m 0750 "${APP_DIR}/jobs"
printf '%s\n' "${SERVICE_USER} ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/ghostshell
chmod 0440 /etc/sudoers.d/ghostshell
visudo -cf /etc/sudoers.d/ghostshell >/dev/null

info "Writing the Shell API application"
cat > "${API_FILE}" <<'PY'
"""Ghost Shell — shell-execution API for a user-managed Linux system."""

import asyncio
import ipaddress
import logging
import os
import signal
import subprocess
import uuid
from datetime import datetime, timezone

from fastapi import FastAPI, Header, HTTPException, Request
from pydantic import BaseModel

TOKEN = os.environ["SHELL_API_TOKEN"]
ACTIVATION_FILE = "/etc/ghost-shell/activation.txt"
ACTIVATION_PHRASE = "I have understood the risk and therefore explicitly grant access to this VM to my Ghost via Ghost Shell."
ALLOWED_IPS = [
    ipaddress.ip_network(entry.strip(), strict=False)
    for entry in os.environ.get("ALLOWED_IPS", "").split(",")
    if entry.strip()
]
OUTPUT_DIR = os.environ.get("SHELL_API_OUTPUT_DIR", "/opt/shellapi/jobs")
os.makedirs(OUTPUT_DIR, exist_ok=True)
TRUSTED_PROXIES = {"127.0.0.1", "::1"}

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("shellapi")
app = FastAPI(title="Ghost Shell")
JOBS: dict = {}
_BACKGROUND_TASKS: set = set()


class Cmd(BaseModel):
    command: str
    timeout: int = 3600
    wait: bool = False


def _client_ip(request: Request) -> str:
    peer = request.client.host if request.client else ""
    if peer in TRUSTED_PROXIES:
        forwarded = request.headers.get("x-forwarded-for")
        if forwarded:
            candidate = forwarded.split(",")[-1].strip()
            if candidate:
                return candidate
    return peer


def _ip_allowed(ip_str: str) -> bool:
    if not ALLOWED_IPS:
        return False
    try:
        addr = ipaddress.ip_address(ip_str)
    except ValueError:
        return False
    return any(addr in net for net in ALLOWED_IPS)


def _guard(request: Request, authorization: str):
    ip_str = _client_ip(request)
    if not _ip_allowed(ip_str):
        log.warning("rejected request from non-allowlisted ip=%s path=%s", ip_str, request.url.path)
        raise HTTPException(403, "forbidden")
    if authorization != f"Bearer {TOKEN}":
        log.warning("rejected request with bad token ip=%s path=%s", ip_str, request.url.path)
        raise HTTPException(401, "unauthorized")


def _require_activation():
    try:
        with open(ACTIVATION_FILE, "r", encoding="utf-8") as activation:
            enabled = activation.read().strip() == ACTIVATION_PHRASE
    except OSError:
        enabled = False
    if not enabled:
        raise HTTPException(
            423,
            "Ghost Shell is locked. Follow the activation instructions on the server.",
        )


def _paths(job_id: str):
    return (os.path.join(OUTPUT_DIR, f"{job_id}.out"), os.path.join(OUTPUT_DIR, f"{job_id}.err"))


def _read(path: str, tail: int = 20000) -> str:
    try:
        with open(path, "r", errors="replace") as f:
            return f.read()[-tail:]
    except FileNotFoundError:
        return ""


def _new_job(command: str) -> str:
    job_id = uuid.uuid4().hex[:12]
    JOBS[job_id] = {
        "status": "running", "command": command,
        "started": datetime.now(timezone.utc).isoformat(),
        "finished": None, "exit": None, "pid": None,
    }
    return job_id


def _job_payload(job_id: str) -> dict:
    out_path, err_path = _paths(job_id)
    return {
        "job_id": job_id, **JOBS.get(job_id, {}),
        "stdout": _read(out_path), "stderr": _read(err_path),
    }


def _kill_group(pid: int):
    try:
        os.killpg(os.getpgid(pid), signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        try:
            os.kill(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass


def _run_inline(job_id: str, command: str, timeout: int) -> dict:
    job = JOBS[job_id]
    out_path, err_path = _paths(job_id)
    proc = None
    with open(out_path, "w") as out, open(err_path, "w") as err:
        try:
            proc = subprocess.Popen(
                command, shell=True, stdin=subprocess.DEVNULL,
                stdout=out, stderr=err, start_new_session=True,
            )
            job["pid"] = proc.pid
            proc.wait(timeout=timeout)
            job["exit"] = proc.returncode
            job["status"] = "done"
        except subprocess.TimeoutExpired:
            if proc is not None:
                _kill_group(proc.pid)
                try:
                    proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    pass
            job["exit"] = -1
            job["status"] = "timeout"
        except Exception as e:
            job["status"] = "error"
            job["error"] = str(e)
            log.exception("inline job %s failed to launch", job_id)
        finally:
            job["finished"] = datetime.now(timezone.utc).isoformat()
    return _job_payload(job_id)


async def _run_background(job_id: str, command: str, timeout: int):
    job = JOBS[job_id]
    out_path, err_path = _paths(job_id)
    proc = None
    try:
        with open(out_path, "wb") as out, open(err_path, "wb") as err:
            proc = await asyncio.create_subprocess_shell(
                command, stdin=asyncio.subprocess.DEVNULL,
                stdout=out, stderr=err, start_new_session=True,
            )
            job["pid"] = proc.pid
            try:
                await asyncio.wait_for(proc.wait(), timeout=timeout)
                job["exit"] = proc.returncode
                job["status"] = "done"
            except asyncio.TimeoutError:
                _kill_group(proc.pid)
                try:
                    await asyncio.wait_for(proc.wait(), timeout=5)
                except asyncio.TimeoutError:
                    pass
                job["exit"] = -1
                job["status"] = "timeout"
    except Exception as e:
        job["status"] = "error"
        job["error"] = str(e)
        log.exception("background job %s failed", job_id)
    finally:
        job["finished"] = datetime.now(timezone.utc).isoformat()


def _spawn_background(job_id: str, command: str, timeout: int):
    task = asyncio.create_task(_run_background(job_id, command, timeout))
    _BACKGROUND_TASKS.add(task)
    task.add_done_callback(_BACKGROUND_TASKS.discard)


@app.post("/exec")
async def exec_cmd(c: Cmd, request: Request, authorization: str = Header(None)):
    _guard(request, authorization)
    _require_activation()
    job_id = _new_job(c.command)
    if c.wait:
        return await asyncio.to_thread(_run_inline, job_id, c.command, c.timeout)
    _spawn_background(job_id, c.command, c.timeout)
    return {"job_id": job_id, "status": "running"}


@app.get("/jobs")
def jobs(
    request: Request,
    authorization: str = Header(None),
    x_job_id: str = Header(None, alias="X-Job-Id"),
):
    _guard(request, authorization)
    _require_activation()
    if x_job_id is None or not x_job_id.strip():
        return {"jobs": [{"job_id": k, **v} for k, v in JOBS.items()]}
    job_id = x_job_id.strip()
    if job_id not in JOBS:
        raise HTTPException(404, "unknown job_id")
    return _job_payload(job_id)


@app.get("/health")
def health():
    return {"ok": True}
PY
chmod 0640 "${API_FILE}"
chown "${SERVICE_USER}:${SERVICE_USER}" "${API_FILE}"

info "Creating the virtual environment and installing Python dependencies"
python3 -m venv "${APP_DIR}/venv"
"${APP_DIR}/venv/bin/pip" install --upgrade pip
"${APP_DIR}/venv/bin/pip" install fastapi uvicorn
chown -R "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}"

info "Writing the secret environment file"
printf 'SHELL_API_TOKEN=%s\nALLOWED_IPS=%s\n' "${TOKEN}" "${ALLOWED_CSV}" > "${ENV_FILE}"
chown "${SERVICE_USER}:${SERVICE_USER}" "${ENV_FILE}"
chmod 0600 "${ENV_FILE}"

info "Installing the locked-by-default activation pin"
install -d -o root -g "${SERVICE_USER}" -m 0750 "${ACTIVATION_DIR}"
printf '%s\n' 'LOCKED' > "${ACTIVATION_FILE}"
chown root:"${SERVICE_USER}" "${ACTIVATION_FILE}"
chmod 0440 "${ACTIVATION_FILE}"

info "Installing the systemd service"
cat > "${UNIT_FILE}" <<'UNIT'
[Unit]
Description=Ghost Shell API
After=network.target

[Service]
User=ghostshell
WorkingDirectory=/opt/shellapi
EnvironmentFile=/opt/shellapi/.env
ExecStart=/opt/shellapi/venv/bin/uvicorn shell_api:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
chmod 0644 "${UNIT_FILE}"

info "Configuring Caddy for HTTPS and both IP gates"
if [[ -f /etc/caddy/Caddyfile ]]; then
    cp -a /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.backup.$(date +%Y%m%d%H%M%S)"
fi
cat > /etc/caddy/Caddyfile <<CADDY
${DOMAIN} {
	@allowed remote_ip ${ALLOWED_CADDY}
	handle @allowed {
		reverse_proxy 127.0.0.1:8000 {
			header_up X-Job-Id {header.X-Job-Id}
		}
	}
	handle {
		respond "Forbidden" 403
	}
}
CADDY
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
    info "Allowing HTTP/HTTPS through active UFW firewall"
    ufw allow 80/tcp
    ufw allow 443/tcp
fi

systemctl daemon-reload
systemctl enable --now shell-api
systemctl enable caddy
systemctl restart caddy

info "Checking service state"
systemctl --no-pager --full status shell-api || true
systemctl --no-pager --full status caddy || true
if ! curl --fail --silent --show-error --max-time 5 http://127.0.0.1:8000/health; then
    die "The API health check failed. Inspect: journalctl -u shell-api -n 100"
fi
printf '\n'

cat <<SUMMARY

INSTALLATION COMPLETE
API URL:       https://${DOMAIN}
Allowed IPs:   ${ALLOWED_CSV}
Health check:  https://${DOMAIN}/health
POST command:  https://${DOMAIN}/exec
GET result:    https://${DOMAIN}/jobs (send X-Job-Id header)
Local config:  ${ENV_FILE}

NEW BEARER TOKEN (copy it now; it will not be printed again):
${TOKEN}

TOKEN WARNING: Anyone with this token AND an allowlisted source IP can run
shell commands with passwordless sudo on this VM. Do not paste it into logs,
source control, or public chat. Rotate it if exposed.

GHOST ACCESS IS LOCKED. To activate it, connect to this VM and replace the
contents of ${ACTIVATION_FILE} with this exact sentence:
${ACTIVATION_PHRASE}

Until the activation file contains that exact sentence, command execution and
job polling remain disabled. To revoke access, remove the sentence or write
LOCKED to the file. This is an activation safeguard; it does not limit Ghost's
system access after activation.

Before using HTTPS, make sure DNS for ${DOMAIN} points to this VM and the
cloud firewall/security group allows inbound TCP 80 and 443. Caddy will obtain
and renew the TLS certificate automatically when those conditions are met.
Never expose port 8000 publicly.

The requester IP allowlist is enforced in both Caddy and the API. To change it
later, edit ALLOWED_IPS in ${ENV_FILE}, update the remote_ip matcher in
/etc/caddy/Caddyfile, then restart shell-api and reload caddy.
SUMMARY
