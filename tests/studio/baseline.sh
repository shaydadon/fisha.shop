#!/usr/bin/env bash
# Prints what visitors and search engines see on key pages (title, description, H1, card count, category links in page
# order, Home card names), so a before/after diff proves the Studio changed nothing visible. Usage: bash tests/studio/baseline.sh > file
for u in / /shop-2/ /product-category/originals/ /product-category/collectibles/clippers/ /product-category/wearables/ /product-category/wearables/tshirts/ /product/out-to-the-river/ /product/upside-down/; do
	html=$(curl -s "http://localhost:8484$u")
	echo "== $u"
	echo "$html" | grep -o -e '<title>[^<]*' -e '<meta name="description" content="[^"]*' | head -2
	echo "$html" | grep -o '<h1[^>]*>.*</h1>' | sed 's/<[^>]*>//g' | head -1
	echo "cards: $(echo "$html" | grep -o 'class="fs-card' | wc -l)"
	echo "category links: $(echo "$html" | grep -o 'href="[^"]*product-category/[^"]*"' | tr '\n' ' ')"
	echo "home cards: $(echo "$html" | grep -o '<h3 class="fh-cat__name">[^<]*' | sed 's/.*>//' | tr '\n' ',')"
done
