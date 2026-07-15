CREATE TABLE tx_nrllm_skill (
    tx_skillspector_check_level varchar(16) DEFAULT '' NOT NULL,
    tx_skillspector_check_report mediumtext,
    tx_skillspector_checked_at int(11) unsigned DEFAULT '0' NOT NULL,
    KEY skillspector_level (tx_skillspector_check_level)
);

