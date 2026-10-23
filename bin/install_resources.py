#!/usr/bin/env python3
"""Prepare only fixed public resources as the unprivileged application user."""
from __future__ import annotations

import os
from pathlib import Path
import pwd
import secrets
import stat

ROOT = Path('/var/lib/gitposted')
SOURCE = Path(__file__).resolve().parents[1] / 'payload/resources'
DIRECTORIES = {'sessions': 0o700, 'runtime': 0o750, 'storage': 0o750, 'tmp': 0o750,
               'assets': 0o755, 'certs': 0o755}
RESOURCES = ('assets/schedule.jpg', 'assets/preview-links.png', 'certs/russiantrustedca.pem')


def prepare(root: Path = ROOT, source: Path = SOURCE) -> None:
    """CLI accepts no paths; parameters are for isolated filesystem fixtures."""
    uid = os.getuid()
    if uid == 0 or uid != pwd.getpwnam('gitposted').pw_uid:
        raise ValueError('Resource preparation requires the GitPosted application UID.')
    root, source = Path(root), Path(source)
    if not root.is_absolute() or root.resolve() != root or not source.is_absolute() or source.resolve() != source:
        raise ValueError('Resource paths must be ordinary absolute directories.')

    def owned_directory(path):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or path.resolve() != path:
            raise ValueError('Application directories must belong to the application UID.')

    owned_directory(root)
    # Validate every source before making any changes. The immutable root-owned
    # bundle is readable by the app; it cannot introduce links or private files.
    for relative in RESOURCES:
        path = source / relative
        info = path.lstat()
        if path.resolve() != path or not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError('Public resource source must be a root-owned regular file.')
    for name, mode in DIRECTORIES.items():
        path = root / name
        if not path.exists() and not path.is_symlink():
            path.mkdir(mode=mode)
        owned_directory(path)
        os.chmod(path, mode, follow_symlinks=False)
    for relative in RESOURCES:
        target = root / relative
        owned_directory(target.parent)
        if target.is_symlink() or (target.exists() and not target.is_file()):
            raise ValueError('Public resource target must be a regular file.')
        temporary = target.parent / ('.' + target.name + '.' + secrets.token_hex(8))
        try:
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            with os.fdopen(fd, 'wb') as stream:
                stream.write((source / relative).read_bytes())
                stream.flush()
                os.fsync(stream.fileno())
                os.fchmod(stream.fileno(), 0o644)
            os.replace(temporary, target)
        finally:
            temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    prepare()
