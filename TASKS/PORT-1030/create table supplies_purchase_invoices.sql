create table supplies_purchase_invoices
(
    `ID`                 int auto_increment,
    `DATE_CREATE`        datetime                default current_timestamp() not null,
    `DATE_UPDATE`        datetime                default current_timestamp() not null on update current_timestamp(),
    `SORT`               int(11)        not null     default 500 not null,
    `ACTIVE`             tinyint        not null     default 1 not null,
    `SUPPLY_CODE`        varchar(50)    not null,
    `TVP_CODE_1C`        varchar(50)    not null,
    `PURCHASE_INVOICE`   varchar(50)    not null,
    `DATE`               DATE           not null,
    primary key (ID)
)
    charset = utf8mb4
    with system versioning;