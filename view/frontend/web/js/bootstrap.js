define(['jquery', 'Magento_Customer/js/customer-data', 'Selectika_AiCommerce/js/runtime'], function (
  $,
  customerData,
  createRuntime
) {
  'use strict';

  return function (config) {
    if (window.selectikaMagento) {
      return;
    }
    var section = customerData.get('selectika-events');
    var runtime = createRuntime(window, document, config, function () {
      if (config.trackingEnabled) {
        customerData.reload(['selectika-events'], true);
      }
    });
    window.selectikaMagento = {
      version: config.version,
      setConsent: runtime.setConsent,
      setVariant: runtime.setVariant,
    };
    window.addEventListener('selectika:consent', function (event) {
      runtime.setConsent(Boolean(event.detail && event.detail.analytics === true));
    });
    section.subscribe(runtime.processSection);
    $(document).on(
      'change.selectika click.selectika updatePrice.selectika',
      '#product_addtocart_form, .swatch-opt, [data-role="priceBox"]',
      function () {
        window.setTimeout(function () {
          var selected = document.querySelector('#product_addtocart_form [name="selected_configurable_option"]');
          runtime.setVariant(selected ? selected.value : '');
        }, 0);
      }
    );
    runtime.start();
  };
});
