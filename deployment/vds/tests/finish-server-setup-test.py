"""Filesystem regression tests; run only inside a disposable Linux container."""
import grp
import importlib.util
import os
from pathlib import Path
import pwd
import stat
import subprocess
import tempfile
import unittest

if not Path('/.dockerenv').exists() or os.geteuid() != 0:
    raise SystemExit('These tests require a disposable root Docker container.')
try:
    pwd.getpwnam('kaevcms')
except KeyError:
    subprocess.run(['useradd', '--system', 'kaevcms'], check=True)
Path('/var/www').mkdir(exist_ok=True)
Path('/var/lib/kaevcms-installer').mkdir(exist_ok=True)
spec = importlib.util.spec_from_file_location('finish_setup', Path(__file__).parents[1] / 'finish-server-setup.py')
finish_setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(finish_setup)


class FinishSetupTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='kaevcms_fixture_', dir='/var/www')
        self.root = Path(self.directory.name)
        (self.root / 'public').mkdir()
        (self.root / 'storage/app').mkdir(parents=True)
        (self.root / '.env').write_text('unchanged test configuration\n')
        self.root.chmod(0o770)
        (self.root / 'public').chmod(0o770)
        self.marker = Path('/var/lib/kaevcms-installer/finalized')
        self.marker.unlink(missing_ok=True)

    def tearDown(self):
        self.marker.unlink(missing_ok=True)
        self.directory.cleanup()

    def complete(self):
        (self.root / 'storage/app/installed.lock').write_text('{}')

    def test_unfinished_installer_keeps_temporary_permissions(self):
        finish_setup.finish(str(self.root))
        self.assertFalse(self.marker.exists())
        self.complete()
        (self.root / 'public/install').mkdir()
        finish_setup.finish(str(self.root))
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode), 0o770)
        self.assertFalse(self.marker.exists())

    def test_completion_closes_permissions_without_rewriting_config(self):
        self.complete()
        finish_setup.finish(str(self.root))
        self.assertTrue(self.marker.exists())
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode), 0o750)
        self.assertEqual(stat.S_IMODE((self.root / 'public').stat().st_mode), 0o750)
        self.assertEqual((self.root / '.env').read_text(), 'unchanged test configuration\n')
        self.assertEqual(stat.S_IMODE((self.root / '.env').stat().st_mode), 0o640)
        self.assertEqual((self.root / '.env').stat().st_uid, pwd.getpwnam('kaevcms').pw_uid)
        self.assertEqual((self.root / '.env').stat().st_gid, grp.getgrnam('www-data').gr_gid)
        finish_setup.finish(str(self.root))

    def test_environment_symlink_cannot_change_another_file(self):
        self.complete()
        target = self.root / 'other-file'
        target.write_text('do not touch')
        target.chmod(0o600)
        (self.root / '.env').unlink()
        (self.root / '.env').symlink_to(target)
        with self.assertRaises(OSError):
            finish_setup.finish(str(self.root))
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
        self.assertEqual(target.stat().st_uid, 0)
        self.assertFalse(self.marker.exists())


unittest.main()
