ALTER TABLE `%TABLE_PREFIX%department`
  ADD `timezone` varchar(64) DEFAULT NULL AFTER `path`,
  ADD `workhours` varchar(64) NOT NULL DEFAULT "[16777215,16777215,16777215,16777215,16777215,16777215,16777215]" AFTER `timezone`,
  ADD `holidays` text NOT NULL DEFAULT "" AFTER `workhours`;
