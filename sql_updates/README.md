# SQL database updates

Place every new database migration in this folder as a `.sql` file.

Naming suggestion: `YYYY_MM_DD_short_description.sql`.

The admin page `database_updates.php` scans this folder and lists migrations not yet recorded in `schema_migrations`. After a migration succeeds, it is recorded and will no longer be shown as pending. Keep old migration files; create a new file for every future change instead of editing a migration that has already run.

Back up the database before applying updates. SQL files may add, alter, or delete tables and columns; destructive statements can permanently remove data.