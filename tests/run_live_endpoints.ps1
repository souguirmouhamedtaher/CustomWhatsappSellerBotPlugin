$key   = 'HHJPZZJ456A4ZT'
$base  = 'http://localhost/ILEYCOM/wordpress/wp-json/whatsapp-bot/v1'
$phone = '21650354773'
$ft    = 'flowtoken-21650354773-1775138636457'

curl.exe -s -w "seller/all -> HTTP %{http_code}`n" -o NUL -H "x-api-key: $key" "$base/seller/all"
curl.exe -s -w "seller/by-phone -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"phone`":`"$phone`"}" "$base/seller/by-phone"
curl.exe -s -w "seller/state/by-phone -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"phone`":`"$phone`"}" "$base/seller/state/by-phone"
curl.exe -s -w "seller/by-flow-token -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/seller/by-flow-token"
curl.exe -s -w "product/categories/list -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"parent_only`":true,`"limit`":10}" "$base/seller/product/categories/list"
curl.exe -s -w "pricing/convert -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"regular_tnd`":`"100`",`"promo_tnd`":`"80`"}" "$base/seller/pricing/convert"
curl.exe -s -w "products/by-flow-token -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`",`"page`":1,`"per_page`":5}" "$base/seller/products/by-flow-token"
curl.exe -s -w "product/list-paged -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`",`"page`":1,`"limit`":5}" "$base/seller/product/list-paged/by-flow-token"
curl.exe -s -w "orders/by-flow-token -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/seller/orders/by-flow-token"
curl.exe -s -w "orders/counters -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/seller/orders/counters/by-flow-token"
curl.exe -s -w "orders/list -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/seller/orders/list/by-flow-token"
curl.exe -s -w "cache/warmup/get -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/cache/auth/warmup/get"
curl.exe -s -w "cache/products/list/get -> HTTP %{http_code}`n" -o NUL -X POST -H "x-api-key: $key" -H "Content-Type: application/json" -d "{`"flow_token`":`"$ft`"}" "$base/cache/products/list/get"
