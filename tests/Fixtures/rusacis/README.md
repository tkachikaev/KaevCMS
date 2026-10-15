# RUSaCis SQL fixtures

These DDL files were supplied by the project owner from the target RUSaCis distribution on 2026-10-15. Only indentation is normalized in the copied fixtures. They cover accounts, characters, clans, Olympiad heroes and castles. The tests adapt only MySQL-specific syntax for an isolated SQLite connection; they do not invent columns or connect to a live game database.

The target distribution stores castle names in `data/xml/castles.xml`. Its compiled `Player` restores hero status from current `heroes.played/active` and unexpired `characters.herountil`, writes `lastAccess` in milliseconds, and stores `onlinetime` in seconds. BCrypt interoperability was checked using the distribution's own `net.sf.l2j.commons.crypt.BCrypt` class.
