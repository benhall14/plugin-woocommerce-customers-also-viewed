(function (blocks, element) {
    const createElement = element.createElement;

    blocks.registerBlockType('conobe/customers-also-viewed', {
        title: 'Customers Also Viewed',
        description: 'Show related product recommendations on a single product page.',
        icon: 'products',
        category: 'widgets',
        edit: function () {
            return createElement(
                'div',
                { className: 'wp-block-conobe-customers-also-viewed' },
                'Customers Also Viewed recommendations appear on single product pages.'
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp.blocks, window.wp.element);
