#!/usr/bin/env python3
"""Close temporary Web Installer permissions; installed outside the web tree."""
import grp
import os
import pwd
import re
import stat
import sys


def finish(root):
    if not re.fullmatch(r"/var/www/[a-zA-Z0-9][a-zA-Z0-9_-]*", root):
        raise ValueError("Unexpected installation directory")
    marker = "/var/lib/kaevcms-installer/finalized"
    if os.path.exists(marker) or not os.path.isfile(root + "/storage/app/installed.lock"):
        return
    if os.path.lexists(root + "/public/install"):
        return  # Web Installer has not yet finished its own cleanup.
    owner = pwd.getpwnam("kaevcms").pw_uid
    group = grp.getgrnam("www-data").gr_gid
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    root_fd = os.open(root, directory_flags)
    try:
        # Lock the parent first, preventing the web worker from replacing entries.
        os.fchmod(root_fd, 0o750)
        public_fd = os.open("public", directory_flags, dir_fd=root_fd)
        try:
            os.fchmod(public_fd, 0o750)
        finally:
            os.close(public_fd)
        for parts in [(".env",), ("storage", "app", "installed.lock")]:
            parent = os.dup(root_fd)
            try:
                for name in parts[:-1]:
                    child = os.open(name, directory_flags, dir_fd=parent)
                    os.close(parent)
                    parent = child
                fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent)
                try:
                    if not stat.S_ISREG(os.fstat(fd).st_mode):
                        raise ValueError("Expected a regular installation file")
                    os.fchown(fd, owner, group)
                    os.fchmod(fd, 0o640)
                finally:
                    os.close(fd)
            finally:
                os.close(parent)
    finally:
        os.close(root_fd)
    fd = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    os.close(fd)
    print("KaevCMS Web Installer finished; temporary write permissions closed.")


if __name__ == "__main__":
    finish(sys.argv[1])
