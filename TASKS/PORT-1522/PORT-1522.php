PORT-1522
<?php

if (is_array($subArray[$keyPart])) return array_filter(array_map(function($elem){return htmlspecialchars($elem);}, $subArray[$keyPart]));
return htmlspecialchars($subArray[$keyPart]);