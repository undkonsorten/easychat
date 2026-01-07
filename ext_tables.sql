#
# Table structure for table 'sys_reaction'
#
CREATE TABLE sys_reaction (
	easychat_configuration int(11) unsigned DEFAULT '0' NOT NULL,
);

create table easychat_messages
(
	id bigint NOT NULL auto_increment,
	messages text NOT NULL,
	PRIMARY KEY (id)
);

