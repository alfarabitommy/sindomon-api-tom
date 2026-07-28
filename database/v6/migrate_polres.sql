ALTER TABLE `tbl_polres`
  CHANGE `id` `polres_id` INT(11) NOT NULL AUTO_INCREMENT,
  CHANGE `nama_polda` `nama_polres` VARCHAR(100) NOT NULL,
  DROP `created_at`;

ALTER TABLE `tbl_polres`
  ADD CONSTRAINT `fk_polres_polda` FOREIGN KEY (`polda_id`) REFERENCES `tbl_polda`(`id`) ON DELETE RESTRICT;
