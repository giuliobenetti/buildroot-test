CREATE TABLE `results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` int(11) NOT NULL DEFAULT '0',
  `builddate` datetime NOT NULL DEFAULT '1970-01-01 00:00:01',
  `submitter` varchar(255) NOT NULL DEFAULT '',
  `commitid` char(40) NOT NULL DEFAULT '',
  `identifier` char(40) NOT NULL DEFAULT '',
  `arch` varchar(64) NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `libc` varchar(32) NOT NULL DEFAULT '',
  `static` tinyint(1) NOT NULL default '0',
  `subarch` varchar(64) NOT NULL DEFAULT '',
  `duration` int(11) NOT NULL DEFAULT '0',
  `branch` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `config_symbol` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `value` varchar(255) NOT NULL DEFAULT '',
  CONSTRAINT unique_entry UNIQUE (name,value),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `symbol_per_result` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `result_id` int(11) NOT NULL DEFAULT '0',
  `symbol_id` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_result_id` FOREIGN KEY (`result_id`) REFERENCES `results`(`id`),
  CONSTRAINT `fk_symbol_id` FOREIGN KEY (`symbol_id`) REFERENCES `config_symbol`(`id`),
  INDEX `ix_symbol_id`(`symbol_id`),
  INDEX `ix_result_id`(`result_id`)

) ENGINE=InnoDB DEFAULT CHARSET=latin1;
