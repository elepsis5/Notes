SELECT 
s1.`id` AS RU_ID, 
s2.`id` AS EN_ID, 
s3.`id` AS FR_ID, 
s4.`id` AS HR_ID, 
s5.`id` AS SR_ID,
s6.`id` AS SQ_ID,
s7.`id` AS BS_ID,
s8.`id` AS MK_ID,
s9.`id` AS NO_ID,
s1.`key` AS L_KEY, 
s1.`value` AS RU_V, 
s2.`value` AS EN_V, 
s3.`value` AS FR_V, 
s4.`value` AS HR_V, 
s5.`value` AS SR_V,
s6.`value` AS SQ_V,
s7.`value` AS BS_V,
s8.`value` AS MK_V,
s9.`value` AS NO_V
FROM `translate_interface` s1
LEFT JOIN translate_interface s2
ON s1.key = s2.key
LEFT JOIN translate_interface s3
ON s1.key = s3.key
LEFT JOIN translate_interface s4
ON s1.key = s4.key
LEFT JOIN translate_interface s5
ON s1.key = s5.key
LEFT JOIN translate_interface s6
ON s1.key = s6.key
LEFT JOIN translate_interface s7
ON s1.key = s7.key
LEFT JOIN translate_interface s8
ON s1.key = s8.key
LEFT JOIN translate_interface s9
ON s1.key = s9.key
WHERE s1.`site_id` = 1 
AND s2.`site_id`=1 
AND s3.`site_id`=1 
AND s4.`site_id`=1 
AND s5.`site_id`=1 
AND s6.`site_id`=1 
AND s7.`site_id`=1 
AND s8.`site_id`=1 
AND s9.`site_id`=1  
AND s1.`lang_id`=2 
AND s2.`lang_id`=3 
AND s3.`lang_id`=5 
AND s4.`lang_id`=7 
AND s5.`lang_id`=8 
AND s6.`lang_id`=9 
AND s7.`lang_id`=10 
AND s8.`lang_id`=11
AND s9.`lang_id`=12;
