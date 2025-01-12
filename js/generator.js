// --------------Order -------------------------------------
jQuery(document).ready(function($) {
    // Tab switching functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $($(this).attr('href')).addClass('active');
    });

    // Add toast container to body if it doesn't exist
    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }


    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    let isGenerating = false;
    let totalOrders = 0;
    let successCount = 0;
    let failedCount = 0;
    let currentBatch = 0;
    let isStopping = false;
    let startTime;

    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    function resetAll() {
        isGenerating = false;
        isStopping = false;
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
        
        $('.progress-bar').css('width', percentage + '%');
        $('#total-processed').text(totalProcessed);
        $('#success-count').text(successCount);
        $('#failed-count').text(failedCount);
        
        const elapsedTime = (Date.now() - startTime) / 1000;
        const ordersPerSecond = totalProcessed / elapsedTime;
        $('#processing-rate').text(ordersPerSecond.toFixed(2));
        
        const remainingOrders = totalOrders - totalProcessed;
        const estimatedSecondsRemaining = remainingOrders / ordersPerSecond;
        $('#time-remaining').text(formatDuration(estimatedSecondsRemaining * 1000));
        $('#elapsed-time').text(formatDuration(Date.now() - startTime));
    }

    function stopGeneration() {
        return $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'stop_order_generation',
                nonce: wcOrderGenerator.nonce
            }
        });
    }

    function processBatch() {
        if (!isGenerating || isStopping) {
            finishGeneration('Order Generation stopped', 'warning');
            showToast('Order Generation stopped', 'warning');
            return;
        }

        const totalProcessed = successCount + failedCount;
        const remainingOrders = totalOrders - totalProcessed;
        
        if (remainingOrders <= 0) {
            finishGeneration('Order Generation complete!', 'success');
            showToast('Order Generation complete!', 'success');
            return;
        }

        const currentBatchSize = Math.min($('#batch_size').val(), remainingOrders);
        console.log(currentBatchSize)
        $('#generation-status')
            .text(`Processing batch ${currentBatch + 1}...`)
            .removeClass()
            .addClass('notice notice-info')
            .show();

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
                    
                    if (!isStopping) {
                        setTimeout(processBatch, 500);
                    } else {
                        finishGeneration('Generation stopped', 'warning');
                        showToast('Order Generation stopped', 'warning');
                        
                    }
                } else {
                    handleError('Error processing batch: ' + response.data);
                    showToast('Eror processing batch', 'error');
                }
            },
            error: function(xhr, status, error) {
                if (!isStopping) {
                    handleError('Server error occurred: ' + error);
                    showToast('Server error occurred', 'error');
                } else {
                    finishGeneration('Generation stopped', 'warning');
                    showToast('Order Generation stopped', 'warning');
                }
            }
        });
    }

    function finishGeneration(message, type) {
        $('#generation-status')
            .text(message)
            .removeClass()
            .addClass(`notice notice-${type}`)
            .show();
            
        isGenerating = false;
        isStopping = false;

        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').show();
    }

    function handleError(message) {
        failedCount += batchSize;
        updateProgress();
        finishGeneration(message, 'error');
    }

    $('#order-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numOrders = parseInt($('#num_orders').val());
        if (numOrders < 1 || numOrders > 10000) {
            alert('Please enter a number between 1 and 10k');
            return;
        }
        const batch_size = parseInt($('#batch_size').val());
        if (batch_size < 5 || batch_size > 30) {
            alert('Please enter a batch number between 5 and 30');
            return;
        }

        isGenerating = true;
        isStopping = false;
        totalOrders = numOrders;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        startTime = Date.now();

        $('#start-generation').prop('disabled', true);
        $('#stop-generation').prop('disabled', false);
        $('#reset-generation').hide();
        $('#generation-status')
            .text('Starting generation...')
            .removeClass()
            .addClass('notice notice-info')
            .show();
        $('.progress-bar').css('width', '0%');
        
        processBatch();
    });

    $('#stop-generation').on('click', function() {
        if (!isGenerating) return;
        
        isStopping = true;
        $(this).prop('disabled', true);
        $('#generation-status')
            .text('Stopping generation...')
            .removeClass()
            .addClass('notice notice-warning')
            .show();

        stopGeneration()
            .fail(function(xhr, status, error) {
                console.error('Failed to stop generation:', error);
            });
    });

    $('#reset-generation').on('click', resetAll);
});



//------------------------------ Product ----------------------------------

jQuery(document).ready(function($) {

    // Add toast container if it doesn't exist
    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }

    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }


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

            showToast('Product Generation stopped', 'warning');

            return;
        }

        const remainingProducts = totalProducts - (productSuccessCount + productFailedCount);
        if (remainingProducts <= 0) {
            $('#product-generation-status').text('Product generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            showToast('Product Generation complete!', 'success');
            return;
        }

        const currentBatchSize = Math.min($('#product_batch_size').val(), remainingProducts);
        $('#product-generation-status').text(`Processing product batch ${currentProductBatch + 1}...`).removeClass().addClass('notice notice-info').show();

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_product_batch',
                nonce: wcOrderGenerator.products_nonce,
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
        if (numProducts < 1 || numProducts > 10000) {
            alert('Please enter a number between 1 and 10k');
            return;
        }

        const product_batch_size = parseInt($('#product_batch_size').val());
        if (product_batch_size < 5 || product_batch_size > 30) {
            alert('Please enter a batch number between 5 and 30');
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

    
});
