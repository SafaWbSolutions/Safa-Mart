<?php
/**
 * Buying guides (SEO content that links to live product listings).
 * Add a new entry here and it automatically appears on /guides, the sitemap
 * and the home page internal links.
 */

/**
 * @return array<string,array{
 *   slug:string,title:string,h1:string,description:string,updated:string,intro:string,
 *   filters:array<string,mixed>,sections:list<array{h2:string,paragraphs:list<string>,bullets?:list<string>}>,
 *   faq:list<array{q:string,a:string}>,related:list<string>
 * }>
 */
function seo_guide_catalog(): array
{
    return [
        'best-mens-shirts-under-499' => [
            'slug'        => 'best-mens-shirts-under-499',
            'title'       => 'Best Men\'s Shirts Under ₹499 in India (2026) – Buying Guide | Safa Mart',
            'h1'          => 'Best men\'s shirts under ₹499 in India',
            'description' => 'Fabric, fit and collar checks for buying men\'s shirts under ₹499, plus live Shopsy, Myntra and Ajio deals.',
            'updated'     => '2026-11-21',
            'intro'       => 'Under ₹499 you can still find a shirt that survives regular wear — if you check the fabric, stitching and return policy. This guide explains what to look for and links to live listings from Shopsy, Myntra, Ajio and Flipkart.',
            'filters'     => ['q' => 'shirt', 'category' => 'Fashion', 'max_price' => 499],
            'sections'    => [
                [
                    'h2'         => 'Fabric matters more than the print',
                    'paragraphs' => [
                        'Most shirts below ₹499 use cotton blends, polyester blends or rayon. For Indian weather, a cotton-rich blend is the safest everyday choice: it breathes, wrinkles less than pure cotton and dries faster.',
                    ],
                    'bullets'    => [
                        'Everyday office wear: cotton or cotton-poly blend in light colours.',
                        'Party or evening: rayon or viscose with a slight sheen.',
                        'Avoid very thin polyester if you sweat easily — it traps heat.',
                        'Check wash care: machine-washable shirts save a lot of effort.',
                        'Dark colours hide stains; light colours show quality of stitching.',
                    ],
                ],
                [
                    'h2'         => 'Fit, collar and sleeve checks',
                    'paragraphs' => [
                        'Read the size chart and compare chest and shoulder measurements with a shirt you already own. Slim fit looks sharper but leaves little room; regular fit is safer for daily wear.',
                        'Collar shape changes the look: a spread collar works with blazers, a button-down collar is casual, and a mandarin collar suits kurtas and indo-western outfits.',
                    ],
                ],
                [
                    'h2'         => 'Check the return policy before ordering',
                    'paragraphs' => [
                        'Shirt fit is personal and photos can hide stitching issues. Prefer listings with easy returns or exchange, and check the seller rating on the marketplace.',
                        'Safa Mart links directly to the official store page so you can read delivery dates, return rules and seller details before paying.',
                    ],
                ],
            ],
            'faq'         => [
                ['q' => 'Are shirts under ₹499 worth buying?', 'a' => 'Yes, for regular casual and office wear. Check fabric composition, seller rating and return policy — those matter more than the discount percentage.'],
                ['q' => 'Which fabric is best for summer shirts?', 'a' => 'Cotton or a cotton-rich blend in light colours. Pure linen is cooler but usually costs more than ₹499.'],
                ['q' => 'How do I pick the right shirt size online?', 'a' => 'Measure the chest and shoulder of a shirt that fits you well and compare with the size chart on the product page. When between sizes, choose the larger one for regular fit.'],
            ],
            'related'     => ['best-kurtas-under-599'],
        ],

        'best-kurtas-under-599' => [
            'slug'        => 'best-kurtas-under-599',
            'title'       => 'Best Kurtas Under ₹599 for Women (2026) – Buying Guide | Safa Mart',
            'h1'          => 'Best kurtas under ₹599 for women',
            'description' => 'Fabric, fit and styling tips for buying kurtas under ₹599, plus live Myntra, Ajio and Shopsy deals.',
            'updated'     => '2026-11-21',
            'intro'       => 'A good everyday kurta under ₹599 is possible if you check the fabric, length and stitching details before ordering. This guide covers what to look for and links to live listings across Myntra, Ajio and Shopsy.',
            'filters'     => ['q' => 'kurta', 'category' => 'Fashion', 'max_price' => 599],
            'sections'    => [
                [
                    'h2'         => 'Fabric first, print later',
                    'paragraphs' => [
                        'Under ₹599, cotton blends and rayon dominate. Pure cotton is cooler but wrinkles; rayon drapes well and photographs nicely. Read the fabric line on the listing — "cotton blend" usually means 60% cotton or less.',
                    ],
                    'bullets'    => [
                        'Daily wear: cotton or cotton-blend straight kurtas are the safest.',
                        'Office or function: rayon or viscose with a slight sheen looks dressier.',
                        'Monsoon and winter: avoid very thin rayon; look for a lined or heavier weave.',
                        'Check the wash care: hand wash only kurtas are harder to maintain every week.',
                    ],
                ],
                [
                    'h2'         => 'Fit and length checks',
                    'paragraphs' => [
                        'Most marketplace listings include a size chart with chest, waist and length in inches. Measure one kurta you already like and compare — do not assume your usual size.',
                        'Straight-cut kurtas below knee length work with leggings and palazzos. A-line cuts hide the waist and are more forgiving if the size is slightly off.',
                    ],
                ],
                [
                    'h2'         => 'Return policy matters more than the discount',
                    'paragraphs' => [
                        'Clothing fit is personal. Before ordering, check whether the listing supports easy returns or exchange. A 10% higher price with a 7-day return is often a better deal than a final-sale discount.',
                        'Safa Mart links directly to the official store page so you can read the return policy, delivery date and seller rating before paying.',
                    ],
                ],
            ],
            'faq'         => [
                ['q' => 'Which fabric is best for daily-wear kurtas under ₹599?', 'a' => 'Cotton or a cotton-rich blend is best for Indian weather. Rayon is softer and dressier but needs gentler washing.'],
                ['q' => 'How do I choose the right kurta size online?', 'a' => 'Measure your chest, waist and preferred length, then compare with the size chart on the product page. When between sizes, pick the larger one for straight cuts.'],
                ['q' => 'Are these kurtas returnable?', 'a' => 'Return and exchange rules are set by the store. Check the policy on the merchant page before ordering.'],
            ],
            'related'     => ['best-mens-shirts-under-499'],
        ],
    ];
}

function seo_guide_slug_map(): array
{
    $map = [];
    foreach (seo_guide_catalog() as $guide) {
        $map[$guide['slug']] = $guide['h1'];
    }
    return $map;
}
