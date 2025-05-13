SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`shipment_status`.`CODE`
FROM `translate`
inner JOIN `shipment_status` ON translate.ROW_ID = shipment_status.ID
WHERE `translate`.`TABLE` = 'shipment_status'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`or_order_product_status`.`NAME`
FROM `translate`
inner JOIN `or_order_product_status` ON translate.ROW_ID = or_order_product_status.ID
WHERE `translate`.`TABLE` = 'or_order_product_status'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`receipts_actions`.`NAME`
FROM `translate`
inner JOIN `receipts_actions` ON translate.ROW_ID = receipts_actions.ID
WHERE `translate`.`TABLE` = 'receipts_actions'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`or_delivery_status`.`TEXT`
FROM `translate`
inner JOIN `or_delivery_status` ON translate.ROW_ID = or_delivery_status.ID
WHERE `translate`.`TABLE` = 'or_delivery_status'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`subscriptions_channels`.`NAME`
FROM `translate`
inner JOIN `subscriptions_channels` ON translate.ROW_ID = subscriptions_channels.ID
WHERE `translate`.`TABLE` = 'subscriptions_channels'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`returns_statuses`.`NAME`
FROM `translate`
inner JOIN `returns_statuses` ON translate.ROW_ID = returns_statuses.ID
WHERE `translate`.`TABLE` = 'returns_statuses'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`pr_brands_type`.`NAME`
FROM `translate`
inner JOIN `pr_brands_type` ON translate.ROW_ID = pr_brands_type.ID
WHERE `translate`.`TABLE` = 'pr_brands_type'
AND `translate`.`LANG` = 15;

SELECT `translate`.`ID`, `translate`.`TABLE`, `translate`.`FIELD`, `translate`.`VALUE`, `translate`.`ROW_ID`,
`webform_types`.`NAME`
FROM `translate`
inner JOIN `webform_types` ON translate.ROW_ID = webform_types.ID
WHERE `translate`.`TABLE` = 'webform_types'
AND `translate`.`LANG` = 15;