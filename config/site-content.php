<?php

return [
    'footer' => [
        'name' => 'NEXF Lifestyle Ltd.',
        'blurb' => 'NEXF Lifestyle is a multivendor marketplace bringing together verified sellers, authentic products and dependable delivery across Bangladesh. Shop thousands of pieces from independent stores in one place.',
        'address' => 'House 42, Road 11, Banani, Dhaka 1213',
        'helpline' => '+880 1700 000 000',
        'email' => 'support@nexf.com',
        'hours' => 'Every day, 9am – 11pm',
        'copyright' => 'NEXF Lifestyle Ltd. All Rights Reserved.',
        'contactTitle' => 'Get in Touch',
        'locatorLabel' => 'Store Locator',
        'locatorHref' => '/stores',
        'helplineLabel' => 'Call Helpline',
        'socials' => [
            ['id' => 'soc-1', 'platform' => 'facebook', 'href' => 'https://facebook.com', 'enabled' => true],
            ['id' => 'soc-2', 'platform' => 'instagram', 'href' => 'https://instagram.com', 'enabled' => true],
            ['id' => 'soc-3', 'platform' => 'youtube', 'href' => 'https://youtube.com', 'enabled' => true],
            ['id' => 'soc-4', 'platform' => 'linkedin', 'href' => 'https://linkedin.com', 'enabled' => true],
        ],
        'supportButtons' => [
            ['id' => 'call', 'href' => '+880 1700 000 000', 'enabled' => true],
            ['id' => 'messenger', 'href' => 'https://m.me/nexflifestyle', 'enabled' => true],
            ['id' => 'whatsapp', 'href' => 'https://wa.me/8801700000000', 'enabled' => true],
        ],
        'footerColumns' => [
            ['id' => 'col-1', 'title' => 'Company', 'links' => [
                ['label' => 'About Us', 'href' => '/about'], ['label' => 'Contact Us', 'href' => '/contact'],
                ['label' => 'Careers', 'href' => '/careers'], ['label' => 'Sell on NEXF', 'href' => '/sell'],
                ['label' => 'Store Locator', 'href' => '/stores'],
            ]],
            ['id' => 'col-2', 'title' => 'Policies', 'links' => [
                ['label' => 'Privacy Policy', 'href' => '/privacy'], ['label' => 'Return Policy', 'href' => '/returns'],
                ['label' => 'Terms & Conditions', 'href' => '/terms'], ['label' => 'Cancellation Policy', 'href' => '/cancellation'],
                ['label' => 'Shipping Policy', 'href' => '/shipping'],
            ]],
        ],
    ],
];
