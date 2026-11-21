<?php
/**
 * Festival / sale tracker pages: /sale/<slug>.
 * Dates are announced by the stores, so the page tracks live deals instead of
 * inventing a countdown.
 */

/**
 * @return array<string,array{
 *   slug:string,platform:string,name:string,season:string,title:string,h1:string,
 *   intro:string,long:string,faq:list<array{q:string,a:string}>
 * }>
 */
function seo_event_catalog(): array
{
    return [
        'big-billion-days' => [
            'slug'     => 'big-billion-days',
            'platform' => 'Flipkart',
            'name'     => 'Flipkart Big Billion Days',
            'season'   => 'Usually September–October',
            'title'    => 'Flipkart Big Billion Days Deals Tracker 2026 | Safa Mart',
            'h1'       => 'Flipkart Big Billion Days — live deal tracker',
            'intro'    => 'Track the strongest Flipkart discounts during Big Billion Days. This page always shows the current live deals, whether or not the sale banner is up.',
            'long'     => 'Big Billion Days is Flipkart\'s biggest sale of the year, usually held around September–October. Exact dates are announced by Flipkart. During the event we refresh this list with the highest-discount listings; outside the event it still shows the best current Flipkart deals so you can compare prices before the sale starts.',
            'faq'      => [
                ['q' => 'When is Flipkart Big Billion Days 2026?', 'a' => 'Flipkart announces the dates a few weeks before the sale, usually around September–October. Safa Mart lists live deals throughout the period.'],
                ['q' => 'Are these prices only available during the sale?', 'a' => 'No. Safa Mart shows the current live Flipkart price at the last check. Sale prices can be lower or higher depending on the offer.'],
            ],
        ],
        'end-of-reason-sale' => [
            'slug'     => 'end-of-reason-sale',
            'platform' => 'Myntra',
            'name'     => 'Myntra End of Reason Sale',
            'season'   => 'Usually December–January and mid-year',
            'title'    => 'Myntra End of Reason Sale (EORS) Deals Tracker | Safa Mart',
            'h1'       => 'Myntra End of Reason Sale — live fashion deals',
            'intro'    => 'Myntra EORS brings deep fashion discounts. Track the best current Myntra listings here before and during the sale.',
            'long'     => 'End of Reason Sale (EORS) is Myntra\'s flagship fashion event, usually run in December–January with another edition mid-year. Dates are announced by Myntra. This tracker keeps a shortlist of high-discount Myntra products with direct links to the official page.',
            'faq'      => [
                ['q' => 'When does Myntra EORS happen?', 'a' => 'Myntra usually runs EORS around December–January, with additional editions through the year. The store announces exact dates.'],
                ['q' => 'Do prices change during EORS?', 'a' => 'Yes. Myntra can change prices and coupons during the sale. Always confirm the final price at checkout.'],
            ],
        ],
        'great-indian-festival' => [
            'slug'     => 'great-indian-festival',
            'platform' => 'Amazon',
            'name'     => 'Amazon Great Indian Festival',
            'season'   => 'Usually September–October',
            'title'    => 'Amazon Great Indian Festival Deals Tracker | Safa Mart',
            'h1'       => 'Amazon Great Indian Festival — live deals',
            'intro'    => 'Amazon\'s festive sale season brings discounts across electronics, home and fashion. Track live Amazon deals here.',
            'long'     => 'The Great Indian Festival is Amazon India\'s festive sale, usually around September–October. Exact dates are announced by Amazon. This page lists the current high-discount Amazon products we track, with the price and saving shown up front.',
            'faq'      => [
                ['q' => 'When is Amazon Great Indian Festival?', 'a' => 'Amazon usually runs it around September–October, timed with the festive season. Dates are announced by Amazon.'],
                ['q' => 'Are bank offers included in the price shown?', 'a' => 'No. The price shown is the listing price. Bank offers and coupons are applied at checkout and may reduce it further.'],
            ],
        ],
        'ajio-big-bold-sale' => [
            'slug'     => 'ajio-big-bold-sale',
            'platform' => 'Ajio',
            'name'     => 'Ajio Big Bold Sale',
            'season'   => 'Multiple editions through the year',
            'title'    => 'Ajio Big Bold Sale Deals Tracker | Safa Mart',
            'h1'       => 'Ajio Big Bold Sale — live fashion deals',
            'intro'    => 'Ajio runs frequent Big Bold Sale editions with extra markdowns on own-brand fashion. Track the current best Ajio deals.',
            'long'     => 'Ajio\'s Big Bold Sale appears multiple times a year, often with an extra coupon layer on top of existing discounts. Dates are announced inside the Ajio app. This tracker shows the highest-discount Ajio listings we currently track.',
            'faq'      => [
                ['q' => 'How often does Ajio Big Bold Sale happen?', 'a' => 'Ajio runs multiple sale editions through the year. The exact schedule is announced in the Ajio app.'],
                ['q' => 'Does Safa Mart add any charges?', 'a' => 'No. You pay the same price on Ajio. Safa Mart may earn a small affiliate commission from the store.'],
            ],
        ],
    ];
}

function seo_event_slug_map(): array
{
    $map = [];
    foreach (seo_event_catalog() as $event) {
        $map[$event['slug']] = $event['name'];
    }
    return $map;
}
