#
# Table structure for table 'sys_reaction'
#
CREATE TABLE sys_reaction (
	easychat_configuration int(11) unsigned DEFAULT '0' NOT NULL,
);

create table tx_easychat_domain_model_session
(
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,

	messages longtext DEFAULT '' NOT NULL,
	session_id varchar(255) NOT NULL,

	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
	starttime int(11) unsigned DEFAULT '0' NOT NULL,
	endtime int(11) unsigned DEFAULT '0' NOT NULL,
	PRIMARY KEY (uid),
	KEY parent (pid),
);

CREATE TABLE tx_easychat_configuration (
		uid int(11) NOT NULL auto_increment,
		pid int(11) DEFAULT '0' NOT NULL,
		name varchar(255) DEFAULT '' NOT NULL,
		model varchar(255) DEFAULT '' NOT NULL,
		system_message longtext NOT NULL,
		url varchar(255) DEFAULT '' NOT NULL,
		api_key varchar(255) DEFAULT '' NOT NULL,
		vector_db varchar(255) DEFAULT '' NOT NULL,
		vector_db_host varchar(255) DEFAULT '' NOT NULL,
		vector_db_port varchar(255) DEFAULT '' NOT NULL,
		vector_db_name varchar(255) DEFAULT '' NOT NULL,


		tstamp int(11) unsigned DEFAULT '0' NOT NULL,
		crdate int(11) unsigned DEFAULT '0' NOT NULL,
		cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
		deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
		hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
		starttime int(11) unsigned DEFAULT '0' NOT NULL,
		endtime int(11) unsigned DEFAULT '0' NOT NULL,

		PRIMARY KEY (uid),
		KEY parent (pid),

);

