// --------------Order -------------------------------------
jQuery(document).ready(function($) {
    // Custom tooltip logic
    $('[data-tooltip]').each(function() {
        var tooltipText = $(this).attr('data-tooltip');
        // Tooltip text is already set via CSS, so we don't need extra JS
    });

    // Your existing code continues...
    let isGenerating = false;
    let totalOrders = 0;
    let successCount = 0;
    let failedCount = 0;
    let currentBatch = 0;
    const batchSize = parseInt(wcOrderGenerator.batch_size);
    let startTime;

    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    function resetAll() {
        isGenerating = false;
        totalOrders = 0;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        
        // Reset all display values
        $('.progress-bar').css('width', '0%');
        $('#total-processed').text('0');
        $('#success-count').text('0');
        $('#failed-count').text('0');
        $('#processing-rate').text('0');
        $('#elapsed-time').text('0s');
        $('#time-remaining').text('--');
        $('#num_orders').val('100');
        
        // Reset buttons
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').hide();
        
        // Clear status
        $('#generation-status').hide();
    }

    function updateProgress() {
        const totalProcessed = successCount + failedCount;
        const percentage = (totalProcessed / totalOrders) * 100;
        
        // Update progress bar
        $('.progress-bar').css('width', percentage + '%');
        
        // Update statistics
        $('#total-processed').text(totalProcessed);
        $('#success-count').text(successCount);
        $('#failed-count').text(failedCount);
        
        // Calculate and update rate
        const elapsedTime = (Date.now() - startTime) / 1000; // seconds
        const ordersPerSecond = totalProcessed / elapsedTime;
        $('#processing-rate').text(ordersPerSecond.toFixed(2));
        
        // Update estimated time remaining
        const remainingOrders = totalOrders - totalProcessed;
        const estimatedSecondsRemaining = remainingOrders / ordersPerSecond;
        $('#time-remaining').text(formatDuration(estimatedSecondsRemaining * 1000));
        
        // Update elapsed time
        $('#elapsed-time').text(formatDuration(Date.now() - startTime));
    }

    function processBatch() {
        if (!isGenerating) {
            $('#generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            return;
        }

        const remainingOrders = totalOrders - (successCount + failedCount);
        if (remainingOrders <= 0) {
            $('#generation-status').text('Generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            return;
        }

        const currentBatchSize = Math.min(batchSize, remainingOrders);
        $('#generation-status').text(`Processing batch ${currentBatch + 1}...`).removeClass().addClass('notice notice-info').show();

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_order_batch',
                nonce: wcOrderGenerator.nonce,
                batch_size: currentBatchSize,
                batch_number: currentBatch
            },
            success: function(response) {
                if (response.success) {
                    successCount += response.data.success;
                    failedCount += response.data.failed;
                    currentBatch++;
                    updateProgress();
                    
                    // Reduced delay between batches for better performance
                    setTimeout(processBatch, 500);
                } else {
                    handleError('Error processing batch: ' + response.data);
                }
            },
            error: function() {
                handleError('Server error occurred');
            }
        });
    }

    function handleError(message) {
        failedCount += currentBatchSize;
        updateProgress();
        $('#generation-status').text(message).removeClass().addClass('notice notice-error').show();
        isGenerating = false;
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').show();
    }

    $('#order-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numOrders = parseInt($('#num_orders').val());
        if (numOrders < 1 || numOrders > wcOrderGenerator.max_orders) {
            alert(`Please enter a number between 1 and ${wcOrderGenerator.max_orders}`);
            return;
        }

        isGenerating = true;
        totalOrders = numOrders;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        startTime = Date.now();

        $('#start-generation').prop('disabled', true);
        $('#stop-generation').prop('disabled', false);
        $('#reset-generation').hide();
        $('#generation-status').text('Starting generation...').removeClass().addClass('notice notice-info').show();
        $('.progress-bar').css('width', '0%');
        
        processBatch();
    });

    $('#stop-generation').on('click', function() {
        isGenerating = false;
        $(this).prop('disabled', true);
        $('#generation-status').text('Stopping generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#reset-generation').on('click', resetAll);
});



//------------------------------ Product ----------------------------------

jQuery(document).ready(function($) {
    // Tab switching functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show corresponding content
        $('.tab-content').removeClass('active');
        $($(this).attr('href')).addClass('active');
    });

    // Your existing order generation code here...
    // (Keep all the existing order generation code)

    // Product Generation Code
    let isGeneratingProducts = false;
    let totalProducts = 0;
    let productSuccessCount = 0;
    let productFailedCount = 0;
    let currentProductBatch = 0;
    let productStartTime;

    function resetProductGeneration() {
        isGeneratingProducts = false;
        totalProducts = 0;
        productSuccessCount = 0;
        productFailedCount = 0;
        currentProductBatch = 0;
        
        // Reset display values
        $('.product-progress-bar').css('width', '0%');
        $('#products-processed').text('0');
        $('#products-failed').text('0');
        
        // Reset buttons
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').hide();
        
        // Clear status
        $('#product-generation-status').hide();
    }

    function updateProductProgress() {
        const totalProcessed = productSuccessCount + productFailedCount;
        const percentage = (totalProcessed / totalProducts) * 100;
        
        // Update progress bar
        $('.product-progress-bar').css('width', percentage + '%');
        
        // Update statistics
        $('#products-processed').text(productSuccessCount);
        $('#products-failed').text(productFailedCount);
    }

    function processProductBatch() {
        if (!isGeneratingProducts) {
            $('#product-generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            return;
        }

        const remainingProducts = totalProducts - (productSuccessCount + productFailedCount);
        if (remainingProducts <= 0) {
            $('#product-generation-status').text('Product generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            return;
        }

        const currentBatchSize = Math.min($('#product_batch_size').val(), remainingProducts);
        $('#product-generation-status').text(`Processing product batch ${currentProductBatch + 1}...`).removeClass().addClass('notice notice-info').show();

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_product_batch',
                nonce: wcOrderGenerator.nonce,
                batch_size: currentBatchSize,
                price_min: $('#price_min').val(),
                price_max: $('#price_max').val(),
                batch_number: currentProductBatch
            },
            success: function(response) {
                if (response.success) {
                    productSuccessCount += response.data.success;
                    productFailedCount += response.data.failed;
                    currentProductBatch++;
                    updateProductProgress();
                    
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
        productFailedCount += parseInt($('#product_batch_size').val());
        updateProductProgress();
        $('#product-generation-status').text(message).removeClass().addClass('notice notice-error').show();
        isGeneratingProducts = false;
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').show();
    }

    $('#product-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numProducts = parseInt($('#num_products').val());
        if (numProducts < 1 || numProducts > 1000) {
            alert('Please enter a number between 1 and 1,000');
            return;
        }

        isGeneratingProducts = true;
        totalProducts = numProducts;
        productSuccessCount = 0;
        productFailedCount = 0;
        currentProductBatch = 0;
        productStartTime = Date.now();

        $('#start-product-generation').prop('disabled', true);
        $('#stop-product-generation').prop('disabled', false);
        $('#reset-product-generation').hide();
        $('#product-generation-status').text('Starting product generation...').removeClass().addClass('notice notice-info').show();
        $('.product-progress-bar').css('width', '0%');
        
        processProductBatch();
    });

    $('#stop-product-generation').on('click', function() {
        isGeneratingProducts = false;
        $(this).prop('disabled', true);
        $('#product-generation-status').text('Stopping product generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#reset-product-generation').on('click', resetProductGeneration);

    // Initialize debug tab
    function updateDebugInfo() {
        if ($('#debug').hasClass('active')) {
            $.ajax({
                url: wcOrderGenerator.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_debug_info',
                    nonce: wcOrderGenerator.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#system-status').html(response.data.status);
                        $('#generation-logs').html(response.data.logs);
                    }
                }
            });
        }
    }

    // Update debug info when tab is activated
    $('.nav-tab[href="#debug"]').on('click', updateDebugInfo);
});
