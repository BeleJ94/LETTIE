-- Date of a mail for lists, filters, statistics and the register: reception, else sending, else registration.
-- Stored (PERSISTENT) and indexed: sorting or filtering on the COALESCE expression could not use an index
-- (measured on 100,000 mails: 2.3 s for the first page of the list).
ALTER TABLE mails
    ADD COLUMN mail_date DATETIME AS (COALESCE(received_at, sent_at, created_at)) PERSISTENT AFTER sent_at,
    ADD KEY idx_mails_site_mail_date (site_id, mail_date),
    ADD KEY idx_mails_mail_date (mail_date),
    ADD KEY idx_mails_site_closed (site_id, closed_at);
