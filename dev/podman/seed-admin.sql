-- Create (or promote) a development administrator.
-- Usage: psql -v email=admin@ctnlist.test -f seed-admin.sql
-- The subscribers insert trigger adds the ALL list membership and the
-- subscriber role; this script confirms that membership and adds the
-- administrator role.

INSERT INTO subscribers (s_email, s_fname, s_lname)
VALUES (LOWER(:'email'), 'Dev', 'Admin')
ON CONFLICT ((LOWER(s_email))) DO NOTHING;

INSERT INTO subscriber_roles (sr_s_id, sr_r_id)
SELECT s.s_id, r.r_id
FROM subscribers s
JOIN roles r ON r.r_key = 'administrator'
WHERE LOWER(s.s_email) = LOWER(:'email')
ON CONFLICT (sr_s_id, sr_r_id) DO NOTHING;

UPDATE list_subscribers ls
SET ls_confirmed = TRUE,
    ls_confirmed_at = COALESCE(ls_confirmed_at, CURRENT_TIMESTAMP)
FROM subscribers s
WHERE ls.ls_s_id = s.s_id
  AND LOWER(s.s_email) = LOWER(:'email')
  AND ls.ls_confirmed = FALSE;

SELECT s.s_id, s.s_uuid, s.s_email, string_agg(r.r_key, ', ' ORDER BY r.r_key) AS roles
FROM subscribers s
JOIN subscriber_roles sr ON sr.sr_s_id = s.s_id
JOIN roles r ON r.r_id = sr.sr_r_id
WHERE LOWER(s.s_email) = LOWER(:'email')
GROUP BY s.s_id, s.s_uuid, s.s_email;
