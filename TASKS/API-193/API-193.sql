alter table returns drop column INN;
alter table returns drop column COMMISSION;

alter table returns_products
    add SUPPLY_CODE varchar(50) null;
alter table returns_products
    add COMMISSION text null;
alter table returns_products
    add constraint COMMISSION
        check (json_valid(`COMMISSION`));

alter table pr_availability_external
    add TVP_CODE_1C varchar(20) null;
alter table pr_availability_external
    add SUPPLY_CODE varchar(50) null;
alter table pr_availability_external
    add COMMISSIONS text null;
alter table pr_availability_external change SUPPLY PURCHASE_INVOICE varchar(50) null;