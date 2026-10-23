"""Bounded stale rate-limit cleanup. Does not touch sessions or publication state."""
import os
import time
from pathlib import Path

root = Path('/var/lib/gitposted/storage/rate-limits')
if root.is_dir() and not root.is_symlink():
    for path in root.iterdir():
        if path.is_file() and not path.is_symlink() and path.suffix == '.json':
            info = path.stat(follow_symlinks=False)
            if info.st_uid == os.getuid() and info.st_mtime < time.time() - 86400:
                path.unlink()
