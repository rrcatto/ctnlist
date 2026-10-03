-- DESTRUCTIVE DEVELOPMENT RESET
-- Run against the per-domain PostgreSQL database only.
-- The shared banlist database is not touched.

DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
GRANT ALL ON SCHEMA public TO CURRENT_USER;
GRANT ALL ON SCHEMA public TO public;