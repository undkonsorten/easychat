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
		vector_db_api_key varchar(255) DEFAULT '' NOT NULL,
		vector_db_embeddings_model varchar(255) DEFAULT '' NOT NULL,
		vector_db_embeddings_url varchar(255) DEFAULT '' NOT NULL,
		vector_db_embeddings_api_key varchar(255) DEFAULT '' NOT NULL,
		vector_db_dimensions int(11) DEFAULT '1536' NOT NULL,
		index_configurations varchar(255) DEFAULT '' NOT NULL,
		vector_db_sync_removals smallint(1) unsigned DEFAULT '0' NOT NULL,


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


#
# Which vector store point belongs to which indexed document, index configuration, index
# run and page. Vector stores can only delete by id in general (StoreInterface::remove()
# in symfony/ai >= 0.4), so this is what lets IndexEventListener find the ids to delete.
#
CREATE TABLE tx_easychat_index_point (
	uid int(11) NOT NULL auto_increment,
	configuration int(11) unsigned DEFAULT '0' NOT NULL,
	point_id varchar(36) DEFAULT '' NOT NULL,
	document_id varchar(36) DEFAULT '' NOT NULL,
	index_configuration int(11) unsigned DEFAULT '0' NOT NULL,
	index_process varchar(128) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY point (configuration, point_id),
	KEY document (configuration, document_id),
	KEY process (configuration, index_configuration, index_process),
);
