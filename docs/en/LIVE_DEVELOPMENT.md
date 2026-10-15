# Live development on a test VM

Use a separate test installation with SSH key access as its deployment owner. The web server keeps read access to source code and write access only to runtime directories. Keep the private key outside the repository. A local SSH alias such as `kaevcms-test` supplies the hostname, user and identity file; the script never stores passwords.

From the source checkout, start:

```powershell
.\deployment\windows\live-dev.ps1
```

The first batch copies application sources, then the process checks saves every two seconds. Refresh the test website to see the result. Ctrl+C stops a foreground session. Use `-Once` for one synchronization or override `-SshHost`, `-RemoteRoot`, `-SourceRoot` and `-PollSeconds`.

The source checkout remains the editing location. Synchronization uses Windows OpenSSH and tar plus PHP and rsync on the VM. Uploads go into a private temporary directory; every batch is validated before application, PHP syntax is checked, files retain deployment-owner/web-group permissions, and Blade's compiled-view cache is cleared. A failed batch is reported and retried. Only source files known during this session can be removed.

`.env`, runtime, uploads, databases, migrations, dependency locks and release metadata are excluded. Database/schema or dependency changes use the normal tested release/update workflow. Modules and themes retain their usual versioning rules. The content editor bundle still requires `npm run build:editor` after changing its source.

For completed changes, run quality and browser gates, bump release metadata and commit/push to Git. Live synchronization is a development aid; it does not record an installed release or replace updater backups, migrations and recovery. Apply the finished cumulative package normally to record the release on the VM.

When changing the deployment owner with the VDS agent installer, rerun the installer so all source trees, including `themes` and `account-themes`, receive the correct ownership. The installer verifies theme-manifest access for both the deployment account and PHP-FPM.
