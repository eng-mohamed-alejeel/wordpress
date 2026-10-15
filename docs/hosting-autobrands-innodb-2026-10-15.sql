-- Target: autobrands.free.je, WordPress prefix wp7x_.
-- Export a complete database backup in phpMyAdmin before running this file.
-- Select the site's database first. No DROP, TRUNCATE or DELETE statements.
-- ALTER TABLE is not transactional; if one conversion fails, stop and inspect
-- the error. Successful conversions can safely remain InnoDB and be rechecked.

SHOW TABLE STATUS WHERE Name IN (
  'wp7x_posts', 'wp7x_postmeta', 'wp7x_terms', 'wp7x_term_taxonomy',
  'wp7x_term_relationships', 'wp7x_termmeta', 'wp7x_options'
);

ALTER TABLE `wp7x_posts` ENGINE=InnoDB;
ALTER TABLE `wp7x_postmeta` ENGINE=InnoDB;
ALTER TABLE `wp7x_terms` ENGINE=InnoDB;
ALTER TABLE `wp7x_term_taxonomy` ENGINE=InnoDB;
ALTER TABLE `wp7x_term_relationships` ENGINE=InnoDB;
ALTER TABLE `wp7x_termmeta` ENGINE=InnoDB;
ALTER TABLE `wp7x_options` ENGINE=InnoDB;

SHOW TABLE STATUS WHERE Name IN (
  'wp7x_posts', 'wp7x_postmeta', 'wp7x_terms', 'wp7x_term_taxonomy',
  'wp7x_term_relationships', 'wp7x_termmeta', 'wp7x_options'
);
-- Verify Engine=InnoDB for all seven tables before retrying the workbook.
