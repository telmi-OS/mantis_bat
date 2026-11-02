"""Offline policy tests; NOT included in deployment ZIP."""
import contextlib
import hashlib
import io
import json
import os
import tempfile
import unittest
from pathlib import Path

from botocore.exceptions import ClientError
import lambda_function as mod


def digest(b): return hashlib.sha256(b).hexdigest()


class FakeBody(io.BytesIO):
    pass


class FakeS3Service:
    def __init__(self): self.state = None; self.etag = None
    def get_object(self, **kwargs):
        if self.state is None:
            raise ClientError({'Error': {'Code': 'NoSuchKey', 'Message': 'none'}}, 'GetObject')
        return {'Body': FakeBody(self.state), 'ETag': self.etag}


class FakeS3:
    def __init__(self, cfg, objs=None):
        self.cfg=cfg
        self.data=dict(objs or {})
        self.archives={}
        self.s3=FakeS3Service()
        self.log=[]
    def objects(self):
        objs={}
        folders=set()
        for rel, value in self.data.items():
            objs[rel]={'key':self.cfg.prefix+rel,'etag':digest(value)[:32],'size':len(value)}
            for n in range(1, len(rel.split('/'))): folders.add('/'.join(rel.split('/')[:n]))
        return objs,folders
    def hash_inventory(self, objs): return {k:digest(self.data[k]) for k in objs}
    def fetch(self, meta):
        rel=meta['key'][len(self.cfg.prefix):]
        value=self.data[rel]
        if digest(value)[:32]!=meta['etag']:
            mod.stop('stale S3 ETag')
        fd,p=tempfile.mkstemp()
        with os.fdopen(fd,'wb') as f: f.write(value)
        return p,digest(value)
    def put(self, key, source, old_etag=None):
        rel=key[len(self.cfg.prefix):]
        if (rel in self.data and old_etag!=digest(self.data[rel])[:32]) or (rel not in self.data and old_etag):
            mod.stop('conditional write failure')
        self.data[rel]=Path(source).read_bytes()
        self.log.append(('put',rel))
        return digest(self.data[rel])[:32]
    def put_bytes(self,key,body,old_etag=None):
        if key==self.cfg.state_key:
            if (self.s3.state is None and old_etag) or (self.s3.state is not None and old_etag!=self.s3.etag):
                mod.stop('state write condition failed')
            self.s3.state=body
            self.s3.etag=digest(body)[:32]
            return self.s3.etag
        if key.startswith(self.cfg.prefix):
            rel=key[len(self.cfg.prefix):]
            self.log.append(('marker',rel))
            return digest(body)[:32]
        mod.stop('unknown key')
    def head(self,key):
        rel=key[len(self.cfg.prefix):]
        value=self.data[rel]
        return {'key':key,'size':len(value),'etag':digest(value)[:32]}
    def archive(self,rel,s3item,expected_sha):
        if digest(self.data[rel])!=expected_sha: mod.stop('archive source changed')
        self.archives[rel]=self.data[rel]
        self.log.append(('archive',rel))


class FakeGhost:
    def __init__(self,objs=None):
        self.data=dict(objs or {})
        self.ids={k:k+'-fid' for k in self.data}
        self.folders={'':None}
        self.log=[]
        self.fail_replace=False
    def tree(self):
        if not self.data: mod.stop('EMPTY TELMI SERVER')
        files={p:{'id':self.ids[p],'sha':digest(b),'size':len(b)} for p,b in self.data.items()}
        for rel in self.data:
            parts=rel.split('/')
            for n in range(1,len(parts)):
                folder='/'.join(parts[:n]);self.folders[folder]=folder+'-folderid'
        return dict(self.folders),files
    def list_folder(self,folder_id=None):
        parent=next((p for p,fid in self.folders.items() if fid==folder_id),'')
        fs=[{'folder_id':fid,'name':p.rsplit('/',1)[-1]} for p,fid in self.folders.items() if p and (p.rsplit('/',1)[0] if '/' in p else '')==parent]
        es=[{'file_id':self.ids[p],'name':p.rsplit('/',1)[-1],'sha256':digest(b),'size_bytes':len(b)} for p,b in self.data.items() if (p.rsplit('/',1)[0] if '/' in p else '')==parent]
        return fs,es
    def download(self,record):
        rel=next(p for p,fid in self.ids.items() if fid==record['id'])
        value=self.data[rel]
        if digest(value)!=record['sha']: mod.stop('remote changed')
        fd,p=tempfile.mkstemp()
        with os.fdopen(fd,'wb') as f: f.write(value)
        return p
    def upload(self,src,rel,fid):
        if rel in self.data: mod.stop('duplicate upload')
        self.data[rel]=Path(src).read_bytes();self.ids[rel]=rel+'-fid'; self.log.append(('upload',rel))
    def replace(self,src,rel,record):
        if self.fail_replace: mod.stop('HTTP 409 file_changed')
        if digest(self.data[rel])!=record['sha']: mod.stop('HTTP 409 file_changed')
        self.data[rel]=Path(src).read_bytes();self.log.append(('replace',rel))
    def create_folder(self,name,parent_id):
        parent=next((p for p,fid in self.folders.items() if fid==parent_id),'')
        path=parent+'/'+name if parent else name
        fid=path+'-folderid';self.folders[path]=fid
        return fid


class DummyContext:
    def get_remaining_time_in_millis(self): return 300000


def config(mode='both', dry=False):
    return mod.Config('bucket','data/','_telmi_sync/state.json','_telmi_sync/conflicts/',
                      'https://example.org','space-id','fake',500,32,5000,52428800,90,mode,dry,False)


class Tests(unittest.TestCase):
    def setup(self,remote,s3,mode='both',dry=False):
        cfg=config(mode,dry);ss=FakeS3(cfg,s3);gg=FakeGhost(remote)
        return mod.Sync(cfg,ss,gg,DummyContext()),ss,gg
    def test_download_new_remote(self):
        sync,s3,ghost=self.setup({'a.txt':b'remote'},{})
        sync.run();self.assertEqual(s3.data['a.txt'],b'remote');self.assertEqual(sync.count['downloads'],1)
    def test_upload_new_s3(self):
        sync,s3,ghost=self.setup({'anchor.txt':b'x'},{'new.txt':b'local'})
        sync.run();self.assertEqual(ghost.data['new.txt'],b'local');self.assertEqual(sync.count['uploads'],1)
    def test_initial_collision_server_wins(self):
        sync,s3,ghost=self.setup({'a.txt':b'remote'},{'a.txt':b'local'})
        sync.run();self.assertEqual(s3.data['a.txt'],b'remote');self.assertEqual(s3.archives['a.txt'],b'local')
    def test_local_change_replaces_same_remote_id(self):
        sync,s3,ghost=self.setup({'a.txt':b'base'},{'a.txt':b'base'})
        sync.run();original=ghost.ids['a.txt'];s3.data['a.txt']=b'changed'
        sync.run();self.assertEqual(ghost.data['a.txt'],b'changed');self.assertEqual(ghost.ids['a.txt'],original)
        self.assertEqual(sync.count['replacements'],1)
    def test_both_change_server_wins(self):
        sync,s3,ghost=self.setup({'a.txt':b'base'},{'a.txt':b'base'})
        sync.run();s3.data['a.txt']=b's3';ghost.data['a.txt']=b'telmi'
        sync.run();self.assertEqual(s3.data['a.txt'],b'telmi');self.assertEqual(s3.archives['a.txt'],b's3')
    def test_remote_delete_never_reuploads(self):
        sync,s3,ghost=self.setup({'anchor':b'x','a.txt':b'content'},{})
        sync.run();ghost.data.pop('a.txt');ghost.ids.pop('a.txt')
        sync.run();self.assertNotIn('a.txt',ghost.data);self.assertIn('a.txt',s3.data)
    def test_empty_server_hard_fails(self):
        sync,s3,ghost=self.setup({}, {'s3-only':b'hello'})
        with self.assertRaises(mod.SyncError): sync.run()
        self.assertNotIn('s3-only',ghost.data)
    def test_dry_run_read_only(self):
        sync,s3,ghost=self.setup({'a.txt':b'abc'},{},dry=True)
        sync.run();self.assertEqual(s3.data,{});self.assertIsNone(s3.s3.state)
    def test_replace_409_hard_fails(self):
        sync,s3,ghost=self.setup({'a.txt':b'base'},{'a.txt':b'base'})
        sync.run();ghost.fail_replace=True;s3.data['a.txt']=b'new'
        with self.assertRaisesRegex(mod.SyncError, '409'):sync.run()
        self.assertEqual(ghost.data['a.txt'],b'base')
    def test_pull_only_no_upload(self):
        sync,s3,ghost=self.setup({'a.txt':b'a'},{'new.txt':b'new'},mode='pull')
        sync.run();self.assertNotIn('new.txt',ghost.data)
    def test_push_only_no_download(self):
        sync,s3,ghost=self.setup({'a.txt':b'a'},{'new.txt':b'new'},mode='push')
        sync.run();self.assertNotIn('a.txt',s3.data);self.assertEqual(ghost.data['new.txt'],b'new')
    def test_nested_folder(self):
        sync,s3,ghost=self.setup({'anchor':b'x'},{'folder/sub/note.txt':b'new'})
        sync.run();self.assertEqual(ghost.data['folder/sub/note.txt'],b'new')

if __name__=='__main__': unittest.main(verbosity=2)
