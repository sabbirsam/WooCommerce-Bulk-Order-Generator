// Utility functions
const Utils = {
    formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    },

    updateProgressBar(selector, percentage) {
        document.querySelector(selector).style.width = `${percentage}%`;
    },

    showStatus(selector, message, type) {
        const statusEl = document.querySelector(selector);
        statusEl.textContent = message;
        statusEl.className = `notice notice-${type}`;
        statusEl.style.display = 'block';
    }
};

// Base Generator Class
class BaseGenerator {
    constructor(config) {
        this.state = {
            isGenerating: false,
            total: 0,
            successCount: 0,
            failedCount: 0,
            currentBatch: 0,
            startTime: null,
            statsInterval: null,
            batchSize: config.batchSize
        };
        this.config = config;
        this.elements = {};
    }

    startRealtimeUpdates() {
        if (this.state.statsInterval) {
            clearInterval(this.state.statsInterval);
        }
        this.state.statsInterval = setInterval(() => this.updateProgress(), 100);
    }

    updateProgress() {
        const totalProcessed = this.state.successCount + this.state.failedCount;
        const percentage = (totalProcessed / this.state.total) * 100;
        
        Utils.updateProgressBar(this.config.progressBarSelector, percentage);
        
        const elapsedTime = Date.now() - this.state.startTime;
        const itemsPerSecond = totalProcessed / (elapsedTime / 1000);
        
        this.elements.totalProcessed.textContent = totalProcessed;
        this.elements.successCount.textContent = this.state.successCount;
        this.elements.failedCount.textContent = this.state.failedCount;
        if (this.elements.processingRate) {
            this.elements.processingRate.textContent = itemsPerSecond.toFixed(2);
        }
        if (this.elements.elapsedTime) {
            this.elements.elapsedTime.textContent = Utils.formatDuration(elapsedTime);
        }
        if (this.elements.timeRemaining && itemsPerSecond > 0) {
            const remaining = this.state.total - totalProcessed;
            const estimatedSecondsRemaining = remaining / itemsPerSecond;
            this.elements.timeRemaining.textContent = Utils.formatDuration(estimatedSecondsRemaining * 1000);
        }
    }

    async processBatch() {
        if (!this.state.isGenerating) {
            this.handleStoppedGeneration();
            return;
        }

        const remaining = this.state.total - (this.state.successCount + this.state.failedCount);
        if (remaining <= 0) {
            this.handleCompletedGeneration();
            return;
        }

        const currentBatchSize = Math.min(this.state.batchSize, remaining);
        Utils.showStatus(this.config.statusSelector, `Processing batch ${this.state.currentBatch + 1}...`, 'info');

        try {
            const response = await fetch(wcOrderGenerator.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(this.getBatchData(currentBatchSize))
            });

            const data = await response.json();
            
            if (data.success) {
                this.state.successCount += data.data.success;
                this.state.failedCount += data.data.failed;
                this.state.currentBatch++;
                setTimeout(() => this.processBatch(), 500);
            } else {
                this.handleError('Error processing batch: ' + data.data);
            }
        } catch (error) {
            this.handleError('Server error occurred');
        }
    }

    handleStoppedGeneration() {
        Utils.showStatus(this.config.statusSelector, 'Generation stopped', 'warning');
        this.updateButtonStates(false, true, true);
    }

    handleCompletedGeneration() {
        Utils.showStatus(this.config.statusSelector, 'Generation complete!', 'success');
        this.updateButtonStates(false, true, true);
        clearInterval(this.state.statsInterval);
    }

    handleError(message) {
        this.state.failedCount += this.state.batchSize;
        this.updateProgress();
        Utils.showStatus(this.config.statusSelector, message, 'error');
        this.state.isGenerating = false;
        this.updateButtonStates(false, true, true);
        clearInterval(this.state.statsInterval);
    }

    updateButtonStates(startDisabled, stopDisabled, showReset) {
        this.elements.startBtn.disabled = startDisabled;
        this.elements.stopBtn.disabled = stopDisabled;
        this.elements.resetBtn.style.display = showReset ? 'block' : 'none';
    }

    reset() {
        this.state = {
            ...this.state,
            isGenerating: false,
            total: 0,
            successCount: 0,
            failedCount: 0,
            currentBatch: 0,
            startTime: null
        };

        clearInterval(this.state.statsInterval);
        this.resetUI();
    }
}

// Order Generator Class
class OrderGenerator extends BaseGenerator {
    constructor() {
        super({
            batchSize: parseInt(wcOrderGenerator.batch_size),
            progressBarSelector: '.progress-bar',
            statusSelector: '#generation-status'
        });
        this.initializeElements();
        this.attachEventListeners();
    }

    initializeElements() {
        this.elements = {
            form: document.getElementById('order-generator-form'),
            startBtn: document.getElementById('start-generation'),
            stopBtn: document.getElementById('stop-generation'),
            resetBtn: document.getElementById('reset-generation'),
            numOrders: document.getElementById('num_orders'),
            progressBar: document.querySelector('.progress-bar'),
            totalProcessed: document.getElementById('total-processed'),
            successCount: document.getElementById('success-count'),
            failedCount: document.getElementById('failed-count'),
            processingRate: document.getElementById('processing-rate'),
            elapsedTime: document.getElementById('elapsed-time'),
            timeRemaining: document.getElementById('time-remaining'),
            status: document.getElementById('generation-status')
        };
    }

    attachEventListeners() {
        this.elements.form.addEventListener('submit', (e) => this.handleSubmit(e));
        this.elements.stopBtn.addEventListener('click', () => this.stopGeneration());
        this.elements.resetBtn.addEventListener('click', () => this.reset());
    }

    getBatchData(currentBatchSize) {
        return {
            action: 'process_order_batch',
            nonce: wcOrderGenerator.nonce,
            batch_size: currentBatchSize,
            batch_number: this.state.currentBatch
        };
    }

    handleSubmit(e) {
        e.preventDefault();
        
        const numOrders = parseInt(this.elements.numOrders.value);
        if (numOrders < 1 || numOrders > wcOrderGenerator.max_orders) {
            alert(`Please enter a number between 1 and ${wcOrderGenerator.max_orders}`);
            return;
        }

        this.state.isGenerating = true;
        this.state.total = numOrders;
        this.state.successCount = 0;
        this.state.failedCount = 0;
        this.state.currentBatch = 0;
        this.state.startTime = Date.now();

        this.updateButtonStates(true, false, false);
        Utils.showStatus(this.config.statusSelector, 'Starting generation...', 'info');
        Utils.updateProgressBar(this.config.progressBarSelector, 0);
        
        this.startRealtimeUpdates();
        this.processBatch();
    }

    resetUI() {
        Utils.updateProgressBar(this.config.progressBarSelector, 0);
        this.elements.totalProcessed.textContent = '0';
        this.elements.successCount.textContent = '0';
        this.elements.failedCount.textContent = '0';
        this.elements.processingRate.textContent = '0';
        this.elements.elapsedTime.textContent = '0s';
        this.elements.timeRemaining.textContent = '--';
        this.elements.numOrders.value = '100';
        
        this.updateButtonStates(false, true, false);
        this.elements.status.style.display = 'none';
    }

    stopGeneration() {
        this.state.isGenerating = false;
        this.elements.stopBtn.disabled = true;
        Utils.showStatus(this.config.statusSelector, 'Stopping generation...', 'warning');
    }
}

// Product Generator Class
class ProductGenerator extends BaseGenerator {
    constructor() {
        super({
            batchSize: parseInt(document.getElementById('product_batch_size').value),
            progressBarSelector: '.product-progress-bar',
            statusSelector: '#product-generation-status'
        });
        this.initializeElements();
        this.attachEventListeners();
    }

    initializeElements() {
        this.elements = {
            form: document.getElementById('product-generator-form'),
            startBtn: document.getElementById('start-product-generation'),
            stopBtn: document.getElementById('stop-product-generation'),
            resetBtn: document.getElementById('reset-product-generation'),
            numProducts: document.getElementById('num_products'),
            priceMin: document.getElementById('price_min'),
            priceMax: document.getElementById('price_max'),
            batchSize: document.getElementById('product_batch_size'),
            progressBar: document.querySelector('.product-progress-bar'),
            totalProcessed: document.getElementById('products-processed'),
            successCount: document.getElementById('products-success'),
            failedCount: document.getElementById('products-failed'),
            status: document.getElementById('product-generation-status')
        };
    }

    attachEventListeners() {
        this.elements.form.addEventListener('submit', (e) => this.handleSubmit(e));
        this.elements.stopBtn.addEventListener('click', () => this.stopGeneration());
        this.elements.resetBtn.addEventListener('click', () => this.reset());
    }

    getBatchData(currentBatchSize) {
        return {
            action: 'process_product_batch',
            nonce: wcOrderGenerator.products_nonce,
            batch_size: currentBatchSize,
            price_min: this.elements.priceMin.value,
            price_max: this.elements.priceMax.value,
            batch_number: this.state.currentBatch
        };
    }

    handleSubmit(e) {
        e.preventDefault();
        
        const numProducts = parseInt(this.elements.numProducts.value);
        if (numProducts < 1 || numProducts > 1000) {
            alert('Please enter a number between 1 and 1,000');
            return;
        }

        this.state.isGenerating = true;
        this.state.total = numProducts;
        this.state.successCount = 0;
        this.state.failedCount = 0;
        this.state.currentBatch = 0;
        this.state.startTime = Date.now();
        this.state.batchSize = parseInt(this.elements.batchSize.value);

        this.updateButtonStates(true, false, false);
        Utils.showStatus(this.config.statusSelector, 'Starting product generation...', 'info');
        Utils.updateProgressBar(this.config.progressBarSelector, 0);
        
        this.startRealtimeUpdates();
        this.processBatch();
    }

    resetUI() {
        Utils.updateProgressBar(this.config.progressBarSelector, 0);
        this.elements.totalProcessed.textContent = '0';
        this.elements.successCount.textContent = '0';
        this.elements.failedCount.textContent = '0';
        this.elements.numProducts.value = '100';
        
        this.updateButtonStates(false, true, false);
        this.elements.status.style.display = 'none';
    }

    stopGeneration() {
        this.state.isGenerating = false;
        this.elements.stopBtn.disabled = true;
        Utils.showStatus(this.config.statusSelector, 'Stopping product generation...', 'warning');
    }
}

// Tab switching functionality
document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
        e.target.classList.add('nav-tab-active');
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelector(e.target.getAttribute('href')).classList.add('active');
    });
});

// Initialize generators when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const orderGenerator = new OrderGenerator();
    const productGenerator = new ProductGenerator();
});