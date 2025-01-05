jQuery(document).ready(function($) {
    // Shared utilities
    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    // Order Generation
    let orderState = {
        isGenerating: false,
        totalOrders: 0,
        successCount: 0,
        failedCount: 0,
        currentBatch: 0,
        startTime: null,
        updateInterval: null
    };

    function updateOrderProgress() {
        const totalProcessed = orderState.successCount + orderState.failedCount;
        const percentage = (totalProcessed / orderState.totalOrders) * 100;
        const elapsedTime = Date.now() - orderState.startTime;
        const ordersPerSecond = totalProcessed / (elapsedTime / 1000);
        
        // Update all stats in real-time
        $('.progress-bar').css('width', percentage + '%');
        $('#total-processed').text(totalProcessed);
        $('#success-count').text(orderState.successCount);
        $('#failed-count').text(orderState.failedCount);
        $('#processing-rate').text(ordersPerSecond.toFixed(2));
        $('#elapsed-time').text(formatDuration(elapsedTime));

        // Calculate remaining time
        if (ordersPerSecond > 0) {
            const remainingOrders = orderState.totalOrders - totalProcessed;
            const estimatedSecondsRemaining = remainingOrders / ordersPerSecond;
            $('#time-remaining').text(formatDuration(estimatedSecondsRemaining * 1000));
        }
    }

    function processOrderBatch() {
        if (!orderState.isGenerating) {
            $('#generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            clearInterval(orderState.updateInterval);
            return;
        }

        const remainingOrders = orderState.totalOrders - (orderState.successCount + orderState.failedCount);
        if (remainingOrders <= 0) {
            $('#generation-status').text('Generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            clearInterval(orderState.updateInterval);
            return;
        }

        const currentBatchSize = Math.min(parseInt(wcOrderGenerator.batch_size), remainingOrders);

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_order_batch',
                nonce: wcOrderGenerator.nonce,
                batch_size: currentBatchSize,
                batch_number: orderState.currentBatch
            },
            success: function(response) {
                if (response.success) {
                    orderState.successCount += response.data.success;
                    orderState.failedCount += response.data.failed;
                    orderState.currentBatch++;
                    setTimeout(processOrderBatch, 500);
                } else {
                    handleOrderError('Error processing batch: ' + response.data);
                }
            },
            error: function() {
                handleOrderError('Server error occurred');
            }
        });
    }

    function handleOrderError(message) {
        orderState.failedCount += parseInt(wcOrderGenerator.batch_size);
        updateOrderProgress();
        $('#generation-status').text(message).removeClass().addClass('notice notice-error').show();
        orderState.isGenerating = false;
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').show();
        clearInterval(orderState.updateInterval);
    }

    // Product Generation
    let productState = {
        isGenerating: false,
        totalProducts: 0,
        successCount: 0,
        failedCount: 0,
        currentBatch: 0,
        startTime: null,
        updateInterval: null
    };

    function updateProductProgress() {
        const totalProcessed = productState.successCount + productState.failedCount;
        const percentage = (totalProcessed / productState.totalProducts) * 100;
        const elapsedTime = Date.now() - productState.startTime;
        const productsPerSecond = totalProcessed / (elapsedTime / 1000);
        
        // Update all stats in real-time
        $('.product-progress-bar').css('width', percentage + '%');
        $('#products-processed').text(totalProcessed);
        $('#products-success').text(productState.successCount);
        $('#products-failed').text(productState.failedCount);
        if ($('#products-rate').length) {
            $('#products-rate').text(productsPerSecond.toFixed(2));
        }
    }

    function processProductBatch() {
        if (!productState.isGenerating) {
            $('#product-generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            clearInterval(productState.updateInterval);
            return;
        }

        const remainingProducts = productState.totalProducts - (productState.successCount + productState.failedCount);
        if (remainingProducts <= 0) {
            $('#product-generation-status').text('Product generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            clearInterval(productState.updateInterval);
            return;
        }

        const currentBatchSize = Math.min($('#product_batch_size').val(), remainingProducts);

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_product_batch',
                nonce: wcOrderGenerator.products_nonce,
                batch_size: currentBatchSize,
                price_min: $('#price_min').val(),
                price_max: $('#price_max').val(),
                batch_number: productState.currentBatch
            },
            success: function(response) {
                if (response.success) {
                    productState.successCount += response.data.success;
                    productState.failedCount += response.data.failed;
                    productState.currentBatch++;
                    setTimeout(processProductBatch, 500);
                } else {
                    handleProductError('Error processing product batch: ' + response.data);
                }
            },
            error: function() {
                handleProductError('Server error occurred during product generation');
            }
        });
    }

    function handleProductError(message) {
        productState.failedCount += parseInt($('#product_batch_size').val());
        updateProductProgress();
        $('#product-generation-status').text(message).removeClass().addClass('notice notice-error').show();
        productState.isGenerating = false;
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').show();
        clearInterval(productState.updateInterval);
    }

    // Event Handlers
    $('#order-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numOrders = parseInt($('#num_orders').val());
        if (numOrders < 1 || numOrders > wcOrderGenerator.max_orders) {
            alert(`Please enter a number between 1 and ${wcOrderGenerator.max_orders}`);
            return;
        }

        orderState.isGenerating = true;
        orderState.totalOrders = numOrders;
        orderState.successCount = 0;
        orderState.failedCount = 0;
        orderState.currentBatch = 0;
        orderState.startTime = Date.now();

        $('#start-generation').prop('disabled', true);
        $('#stop-generation').prop('disabled', false);
        $('#reset-generation').hide();
        $('#generation-status').text('Starting generation...').removeClass().addClass('notice notice-info').show();
        $('.progress-bar').css('width', '0%');
        
        // Start real-time updates
        orderState.updateInterval = setInterval(updateOrderProgress, 100);
        processOrderBatch();
    });

    $('#product-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numProducts = parseInt($('#num_products').val());
        if (numProducts < 1 || numProducts > 1000) {
            alert('Please enter a number between 1 and 1,000');
            return;
        }

        productState.isGenerating = true;
        productState.totalProducts = numProducts;
        productState.successCount = 0;
        productState.failedCount = 0;
        productState.currentBatch = 0;
        productState.startTime = Date.now();

        $('#start-product-generation').prop('disabled', true);
        $('#stop-product-generation').prop('disabled', false);
        $('#reset-product-generation').hide();
        $('#product-generation-status').text('Starting product generation...').removeClass().addClass('notice notice-info').show();
        $('.product-progress-bar').css('width', '0%');
        
        // Start real-time updates
        productState.updateInterval = setInterval(updateProductProgress, 100);
        processProductBatch();
    });

    $('#stop-generation').on('click', function() {
        orderState.isGenerating = false;
        $(this).prop('disabled', true);
        $('#generation-status').text('Stopping generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#stop-product-generation').on('click', function() {
        productState.isGenerating = false;
        $(this).prop('disabled', true);
        $('#product-generation-status').text('Stopping product generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#reset-generation').on('click', function() {
        clearInterval(orderState.updateInterval);
        $('.progress-bar').css('width', '0%');
        $('#total-processed').text('0');
        $('#success-count').text('0');
        $('#failed-count').text('0');
        $('#processing-rate').text('0');
        $('#elapsed-time').text('0s');
        $('#time-remaining').text('--');
        $('#num_orders').val('100');
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').hide();
        $('#generation-status').hide();
    });

    $('#reset-product-generation').on('click', function() {
        clearInterval(productState.updateInterval);
        $('.product-progress-bar').css('width', '0%');
        $('#products-processed').text('0');
        $('#products-success').text('0');
        $('#products-failed').text('0');
        if ($('#products-rate').length) $('#products-rate').text('0');
        $('#num_products').val('100');
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').hide();
        $('#product-generation-status').hide();
    });

    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $($(this).attr('href')).addClass('active');
    });
});