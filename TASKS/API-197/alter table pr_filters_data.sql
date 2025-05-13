alter table `pr_filters_data`
    add `GENERAL_COMMISSION` tinyint default 0 not null;
alter table `pr_filters_data`
    add `PERSONAL_COMMISSIONS` text default '[]' not null;
