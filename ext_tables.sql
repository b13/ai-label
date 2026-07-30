CREATE TABLE tx_ailabel_domain_model_meta (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    tablename varchar(255) DEFAULT '' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    ai_created tinyint(1) unsigned DEFAULT '0' NOT NULL,
    ai_modified tinyint(1) unsigned DEFAULT '0' NOT NULL,
    reviewed_by int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY table_record (tablename(191), uid_foreign)
);
