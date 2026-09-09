jQuery(document).ready(function ($) {
  // Intercept add to cart buttons
  $(document).on(
    "click",
    'a.add_to_cart_button, button.single_add_to_cart_button, button[name="add-to-cart"]',
    function (e) {
      var $button = $(this);
      var product_id =
        $button.data("product_id") ||
        $button.closest("form").find('[name="add-to-cart"]').val();
      var quantity =
        $button.data("quantity") ||
        $button.closest("form").find('[name="quantity"]').val() ||
        1;
      var variation_id =
        $button.data("variation_id") ||
        $button.closest("form").find('[name="variation_id"]').val() ||
        null;

      if (!product_id) return true; // Let it proceed if no product_id

      e.preventDefault(); // Prevent default action

      // Show loading
      $button.prop("disabled", true).text("Checking...");

      // AJAX call to check inventory
      $.ajax({
        url: pos_inventory_ajax.ajax_url,
        type: "POST",
        data: {
          action: "check_inventory_before_cart",
          product_id: product_id,
          quantity: quantity,
          variation_id: variation_id,
          nonce: pos_inventory_ajax.nonce,
        },
        success: function (response) {
          $button
            .prop("disabled", false)
            .text($button.data("original-text") || "Add to Cart");

          if (response.success) {
            if (response.data.out_of_stock) {
              // Show modal
              showOutOfStockModal();
            } else {
              // Proceed with add to cart via AJAX
              var ajaxData = {
                action: "woocommerce_ajax_add_to_cart",
                product_id: product_id,
                quantity: quantity,
              };

              if (variation_id) {
                ajaxData.variation_id = variation_id;
              }

              // Add variation attributes if any
              $button
                .closest("form")
                .find('input[name^="attribute_"]')
                .each(function () {
                  ajaxData[$(this).attr("name")] = $(this).val();
                });

              $.post(
                pos_inventory_ajax.ajax_url,
                ajaxData,
                function (response) {
                  // WooCommerce will handle the response
                  $(document.body).trigger("wc_fragment_refresh");
                  // You can add custom success message if needed
                }
              );
            }
          } else {
            alert("Error checking inventory. Please try again.");
          }
        },
        error: function () {
          $button
            .prop("disabled", false)
            .text($button.data("original-text") || "Add to Cart");
          alert("Error checking inventory. Please try again.");
        },
      });

      return false;
    }
  );

  // Store original button text
  $(document).on(
    "mouseenter",
    ".add_to_cart_button, .single_add_to_cart_button",
    function () {
      if (!$(this).data("original-text")) {
        $(this).data("original-text", $(this).text());
      }
    }
  );
});

function showOutOfStockModal() {
  // Create modal if it doesn't exist
  if (!$("#pos-out-of-stock-modal").length) {
    $("body").append(`
            <div id="pos-out-of-stock-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div style="
                    background: white;
                    padding: 20px;
                    border-radius: 5px;
                    max-width: 400px;
                    text-align: center;
                ">
                    <h3>Out of Stock</h3>
                    <p>Sorry, this product is currently out of stock or insufficient inventory.</p>
                    <button id="pos-modal-close" style="
                        background: #007cba;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 3px;
                        cursor: pointer;
                    ">Close</button>
                </div>
            </div>
        `);

    // Close modal on button click or outside click
    $("#pos-modal-close, #pos-out-of-stock-modal").on("click", function () {
      $("#pos-out-of-stock-modal").remove();
    });
  }

  $("#pos-out-of-stock-modal").show();
}
