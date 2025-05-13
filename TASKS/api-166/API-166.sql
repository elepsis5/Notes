alter table pr_products modify CODE_1C int default null null;
alter table pr_products add SENT_TO_1C int(1) default 0 null;

INSERT INTO pr_characteristics (DATE_CREATE, DATE_UPDATE, SORT, ACTIVE, CATALOG_TYPE_ID, CHAR_GROUP_ID, CODE, NAME, DIMENSION, EXT_ID, IS_NUM, FILTER_VIEW, BASE) VALUES (DEFAULT, DEFAULT, DEFAULT, DEFAULT, 1, null, 'brand_name', 'Название бренда', null, null, 0, null, 0);
INSERT INTO pr_characteristics (DATE_CREATE, DATE_UPDATE, SORT, ACTIVE, CATALOG_TYPE_ID, CHAR_GROUP_ID, CODE, NAME, DIMENSION, EXT_ID, IS_NUM, FILTER_VIEW, BASE) VALUES (DEFAULT, DEFAULT, DEFAULT, DEFAULT, 1, null, 'external_product_id', 'ID товара поставщика', null, null, 1, null, DEFAULT);
INSERT INTO pr_characteristics (DATE_CREATE, DATE_UPDATE, SORT, ACTIVE, CATALOG_TYPE_ID, CHAR_GROUP_ID, CODE, NAME, DIMENSION, EXT_ID, IS_NUM, FILTER_VIEW, BASE) VALUES (DEFAULT, DEFAULT, DEFAULT, DEFAULT, 1, null, 'folder_1c_ext_id', 'Номер папки 1С', null, null, 1, null, 0);