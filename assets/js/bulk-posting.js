/**
 * HashPoster Bulk Posting JavaScript
 * Handles bulk posting functionality
 */

class HashPosterBulkPosting {
    constructor() {
        this.searchTimeout = null;
        this.isPublishing = false;
        console.log('HashPosterBulkPosting initialized');
        console.log('hashposterBulkPosting object:', hashposterBulkPosting);
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadPosts();
    }

    bindEvents() {
        console.log('HashPoster: Binding events...');
        
        // Filter changes
        const postTypeEl = document.getElementById('hashposter-post-type');
        const postStatusEl = document.getElementById('hashposter-post-status');
        const categoryEl = document.getElementById('hashposter-category');
        const searchEl = document.getElementById('hashposter-search');

        if (postTypeEl) postTypeEl.addEventListener('change', () => this.loadPosts());
        if (postStatusEl) postStatusEl.addEventListener('change', () => this.loadPosts());
        if (categoryEl) categoryEl.addEventListener('change', () => this.loadPosts());
        if (searchEl) searchEl.addEventListener('input', () => this.debouncedSearch());

        // Custom content toggle
        const customContentCheckbox = document.getElementById('hashposter-use-custom-content');
        if (customContentCheckbox) {
            customContentCheckbox.addEventListener('change', () => this.toggleCustomContent());
        }

        // Use event delegation for bulk action buttons to ensure they work even if dynamically created
        document.addEventListener('click', (e) => {
            if (e.target.id === 'hashposter-preview-bulk') {
                console.log('HashPoster: Preview button clicked via delegation');
                e.preventDefault();
                this.previewBulk();
            } else if (e.target.id === 'hashposter-publish-bulk') {
                console.log('HashPoster: Publish button clicked via delegation');
                e.preventDefault();
                this.publishBulk();
            }
        });

        // Select all checkbox - also use delegation
        document.addEventListener('change', (e) => {
            if (e.target.id === 'hashposter-select-all') {
                console.log('HashPoster: Select all checkbox changed via delegation');
                this.toggleSelectAll();
            }
        });
    }

    loadPosts() {
        const postType = document.getElementById('hashposter-post-type')?.value || 'post';
        const postStatus = document.getElementById('hashposter-post-status')?.value || 'publish';
        const search = document.getElementById('hashposter-search')?.value || '';
        const category = document.getElementById('hashposter-category')?.value || '';

        const postsListEl = document.getElementById('hashposter-posts-list');
        if (!postsListEl) return;

        postsListEl.innerHTML = '<p>' + (hashposterBulkPosting.strings?.loading || 'Loading...') + '</p>';

        const formData = new FormData();
        formData.append('action', 'hashposter_get_posts_for_bulk');
        formData.append('post_type', postType);
        formData.append('post_status', postStatus);
        formData.append('search', search);
        formData.append('category', category);
        formData.append('nonce', hashposterBulkPosting.nonce);

        fetch(hashposterBulkPosting.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                postsListEl.innerHTML = data.data.html;
                this.bindPostCheckboxes();
                this.bindSelectAllCheckbox();
            } else {
                postsListEl.innerHTML = '<p>' + (hashposterBulkPosting.strings?.no_posts || 'No posts found.') + '</p>';
            }
        })
        .catch(error => {
            console.error('Error loading posts:', error);
            postsListEl.innerHTML = '<p>Error loading posts.</p>';
        });
    }

    bindPostCheckboxes() {
        const checkboxes = document.querySelectorAll('.hashposter-post-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => this.updateSelectionCount());
        });
    }

    bindSelectAllCheckbox() {
        console.log('HashPoster: Binding select all checkbox...');
        const selectAllCheckbox = document.getElementById('hashposter-select-all-posts');
        console.log('HashPoster: Select all checkbox found:', !!selectAllCheckbox);
        
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', () => {
                console.log('HashPoster: Select all toggled to:', selectAllCheckbox.checked);
                const postCheckboxes = document.querySelectorAll('.hashposter-post-checkbox');
                console.log('HashPoster: Found', postCheckboxes.length, 'post checkboxes');
                
                postCheckboxes.forEach(checkbox => {
                    checkbox.checked = selectAllCheckbox.checked;
                });
                
                const selectedCount = document.querySelectorAll('.hashposter-post-checkbox:checked').length;
                console.log('HashPoster: Selected posts after toggle:', selectedCount);
                this.updateSelectionCount();
            });
        }
    }

    debouncedSearch() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.loadPosts();
        }, 500);
    }

    toggleCustomContent() {
        const isChecked = document.getElementById('hashposter-use-custom-content')?.checked;
        const customContentSection = document.getElementById('hashposter-custom-content-section');
        if (customContentSection) {
            customContentSection.style.display = isChecked ? 'block' : 'none';
        }
    }

    toggleSelectAll() {
        const selectAllCheckbox = document.getElementById('hashposter-select-all');
        const postCheckboxes = document.querySelectorAll('.hashposter-post-checkbox');
        
        postCheckboxes.forEach(checkbox => {
            checkbox.checked = selectAllCheckbox.checked;
        });
        
        this.updateSelectionCount();
    }

    updateSelectionCount() {
        const selectedPosts = document.querySelectorAll('.hashposter-post-checkbox:checked');
        const countEl = document.getElementById('hashposter-selection-count');
        if (countEl) {
            countEl.textContent = selectedPosts.length + ' posts selected';
        }
    }

    previewBulk() {
        const selectedPosts = document.querySelectorAll('.hashposter-post-checkbox:checked');
        const selectedPlatforms = document.querySelectorAll('input[name="platforms[]"]:checked');

        if (selectedPosts.length === 0) {
            alert(hashposterBulkPosting.strings?.select_posts || 'Please select at least one post.');
            return;
        }

        if (selectedPlatforms.length === 0) {
            alert(hashposterBulkPosting.strings?.select_platforms || 'Please select at least one platform.');
            return;
        }

        this.showNotice('Preview functionality: ' + selectedPosts.length + ' posts will be published to ' + selectedPlatforms.length + ' platforms.', 'info');
    }

    publishBulk() {
        console.log('HashPoster: publishBulk called');
        console.log('HashPoster: this object:', this);
        console.log('HashPoster: publishPosts method exists:', typeof this.publishPosts === 'function');
        
        if (this.isPublishing) {
            console.log('HashPoster: Already publishing, ignoring...');
            return;
        }

        const selectedPosts = document.querySelectorAll('.hashposter-post-checkbox:checked');
        const selectedPlatforms = document.querySelectorAll('input[name="platforms[]"]:checked');

        console.log('HashPoster: Selected posts:', selectedPosts.length);
        console.log('HashPoster: Selected platforms:', selectedPlatforms.length);

        if (selectedPosts.length === 0) {
            alert(hashposterBulkPosting.strings?.select_posts || 'Please select at least one post.');
            return;
        }

        if (selectedPlatforms.length === 0) {
            alert(hashposterBulkPosting.strings?.select_platforms || 'Please select at least one platform.');
            return;
        }

        // Confirm action
        const confirmMessage = (hashposterBulkPosting.strings?.confirm_publish || 'Are you sure you want to publish %d posts?').replace('%d', selectedPosts.length);
        if (!confirm(confirmMessage)) {
            return;
        }

        this.isPublishing = true;

        // Collect data
        const postIds = Array.from(selectedPosts).map(checkbox => checkbox.value);
        const platforms = Array.from(selectedPlatforms).map(checkbox => checkbox.value);

        const customContent = document.getElementById('hashposter-use-custom-content')?.checked ?
            document.getElementById('hashposter-custom-content')?.value || '' : '';
        const hashtags = document.getElementById('hashposter-custom-hashtags')?.value || '';
        const skipFeaturedImage = document.getElementById('hashposter-skip-featured-image')?.checked || false;
        const preferUrlCards = document.getElementById('hashposter-prefer-url-cards')?.checked || false;
        
        const postingSchedule = document.querySelector('input[name="posting_schedule"]:checked')?.value;
        const staggerMinutes = postingSchedule === 'staggered' ?
            parseInt(document.getElementById('hashposter-stagger-minutes')?.value || '5') : 0;

        console.log('Checkbox values:', {
            skipFeaturedImage: skipFeaturedImage,
            preferUrlCards: preferUrlCards,
            skipElement: document.getElementById('hashposter-skip-featured-image'),
            preferElement: document.getElementById('hashposter-prefer-url-cards'),
            skipChecked: document.getElementById('hashposter-skip-featured-image')?.checked,
            preferChecked: document.getElementById('hashposter-prefer-url-cards')?.checked
        });

        console.log('Data collected:', {
            postIds,
            platforms,
            customContent,
            hashtags,
            skipFeaturedImage,
            preferUrlCards,
            staggerMinutes
        });

        // Show progress
        this.showProgress();
        this.disablePublishButton();

        // Start publishing with error handling
        try {
            console.log('HashPoster: About to call publishPosts...');
            if (typeof this.publishPosts !== 'function') {
                throw new Error('publishPosts method is not available');
            }
            this.publishPosts(postIds, platforms, customContent, hashtags, skipFeaturedImage, staggerMinutes, preferUrlCards);
        } catch (error) {
            console.error('HashPoster: Error calling publishPosts:', error);
            this.isPublishing = false;
            this.enablePublishButton();
            this.hideProgress();
            alert('Error starting bulk publishing: ' + error.message);
        }
    }

    showProgress() {
        const progressEl = document.getElementById('hashposter-bulk-progress');
        if (progressEl) {
            progressEl.style.display = 'block';
            progressEl.innerHTML = `
                <h4>Publishing Progress</h4>
                <div class="hashposter-progress-bar">
                    <div class="hashposter-progress-fill" style="width: 0%;"></div>
                </div>
                <p class="hashposter-progress-text">Starting...</p>
                <div id="hashposter-progress-details"></div>
            `;
        }
    }

    hideProgress() {
        const progressEl = document.getElementById('hashposter-bulk-progress');
        if (progressEl) {
            progressEl.style.display = 'none';
        }
    }

    disablePublishButton() {
        const publishBtn = document.getElementById('hashposter-publish-bulk');
        if (publishBtn) {
            publishBtn.disabled = true;
            publishBtn.textContent = 'Publishing...';
        }
    }

    enablePublishButton() {
        const publishBtn = document.getElementById('hashposter-publish-bulk');
        if (publishBtn) {
            publishBtn.disabled = false;
            publishBtn.textContent = 'Publish Selected Posts';
        }
        this.isPublishing = false;
    }

    async publishPosts(postIds, platforms, customContent, hashtags, skipFeaturedImage, staggerMinutes, preferUrlCards) {
        console.log('Starting publishPosts with:', {
            postIds,
            platforms,
            customContent,
            hashtags,
            skipFeaturedImage,
            staggerMinutes
        });
        
        const totalPosts = postIds.length;
        const results = [];

        for (let i = 0; i < totalPosts; i++) {
            const postId = postIds[i];
            
            console.log(`Processing post ${i + 1}/${totalPosts}: ${postId}`);
            this.updateProgress(i, totalPosts, `Publishing post ${postId}...`);

            try {
                const result = await this.publishSinglePost(postId, platforms, customContent, hashtags, skipFeaturedImage, preferUrlCards);
                results.push(result);
                
                // Parse the result to show detailed platform information
                let message;
                if (result.success) {
                    const successCount = Object.values(result.platforms || {}).filter(p => p.success).length;
                    const totalCount = Object.keys(result.platforms || {}).length;
                    message = `✓ Post ${postId}: ${successCount}/${totalCount} platforms successful`;
                } else {
                    // Show specific platform failures
                    const failedPlatforms = [];
                    Object.entries(result.platforms || {}).forEach(([platform, status]) => {
                        if (!status.success) {
                            failedPlatforms.push(`${platform}: ${status.message}`);
                        }
                    });
                    message = `✗ Post ${postId} failed: ${failedPlatforms.join(', ')}`;
                }
                
                console.log('Result for post', postId, ':', result);
                this.updateProgress(i + 1, totalPosts, message);

                // Add delay for staggered posting
                if (staggerMinutes > 0 && i < totalPosts - 1) {
                    console.log(`Waiting ${staggerMinutes} minutes before next post...`);
                    await this.delay(staggerMinutes * 60 * 1000);
                }
                
            } catch (error) {
                console.error('Error publishing post:', error);
                const message = `✗ Post ${postId} error: ${error.message}`;
                this.updateProgress(i + 1, totalPosts, message);
                results.push({
                    post_id: postId,
                    success: false,
                    message: error.message
                });
            }
        }

        console.log('Publishing completed. Results:', results);
        this.updateProgress(totalPosts, totalPosts, 'Bulk publishing completed!');
        this.enablePublishButton();
        this.showResults(results);
    }

    async publishSinglePost(postId, platforms, customContent, hashtags, skipFeaturedImage, preferUrlCards) {
        console.log('Publishing post:', postId, 'to platforms:', platforms);
        
        const formData = new FormData();
        formData.append('action', 'hashposter_bulk_publish');
        formData.append('post_ids[]', postId);
        platforms.forEach(platform => formData.append('platforms[]', platform));
        formData.append('custom_content', customContent);
        formData.append('hashtags', hashtags);
        // Always include skip and prefer flags as explicit '1' or '0' strings
        formData.append('skip_featured_image', skipFeaturedImage ? '1' : '0');
        // Defensive: if preferUrlCards is undefined for any reason, attempt to read directly from DOM
        let preferValue = (typeof preferUrlCards !== 'undefined') ? preferUrlCards : (document.getElementById('hashposter-prefer-url-cards')?.checked || false);
        formData.append('prefer_url_cards', preferValue ? '1' : '0');
        formData.append('stagger_minutes', '0'); // Handle staggering in JS
        formData.append('nonce', hashposterBulkPosting.nonce);

        console.log('FormData contents:');
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + pair[1]);
        }

        // Extra defensive log right before sending network request
        console.log('Final payload before fetch: skip_featured_image=', formData.get('skip_featured_image'), 'prefer_url_cards=', formData.get('prefer_url_cards'));

        try {
            const response = await fetch(hashposterBulkPosting.ajax_url, {
                method: 'POST',
                body: formData
            });

            console.log('Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            console.log('Response data:', data);

            // PHP returns: wp_send_json_success( array( 'results' => $results ) );
            // So structure is: data.success = true, data.data.results = array
            if (data.success && data.data && data.data.results && data.data.results.length > 0) {
                return data.data.results[0];
            } else {
                throw new Error(data.data?.message || data.message || 'Failed to publish post');
            }
        } catch (error) {
            console.error('AJAX Error:', error);
            throw error;
        }
    }

    updateProgress(completed, total, message) {
        console.log('Progress update:', completed, '/', total, ':', message);
        
        const percentage = (completed / total) * 100;
        
        const progressFill = document.querySelector('.hashposter-progress-fill');
        if (progressFill) {
            progressFill.style.width = percentage + '%';
            console.log('Progress bar updated to:', percentage + '%');
        } else {
            console.log('Progress fill element not found');
        }

        const progressText = document.querySelector('.hashposter-progress-text');
        if (progressText) {
            progressText.textContent = `Publishing (${completed}/${total}) - ${Math.round(percentage)}%`;
        } else {
            console.log('Progress text element not found');
        }

        const progressDetails = document.getElementById('hashposter-progress-details');
        if (progressDetails) {
            progressDetails.innerHTML = message + '<br>' + progressDetails.innerHTML;
        } else {
            console.log('Progress details element not found');
        }
    }

    showResults(results) {
        const successCount = results.filter(r => r.success).length;
        const totalCount = results.length;
        
        let message = `Bulk publishing completed! ${successCount}/${totalCount} posts published successfully.`;
        
        // Show detailed error messages for failed posts
        const failedResults = results.filter(r => !r.success);
        if (failedResults.length > 0) {
            message += '\n\nFailed posts:';
            failedResults.forEach(result => {
                let errorMessage = 'Unknown error';
                
                // If we have platform-specific errors, show them
                if (result.platforms) {
                    const failedPlatforms = [];
                    Object.entries(result.platforms).forEach(([platform, status]) => {
                        if (!status.success) {
                            failedPlatforms.push(`${platform}: ${status.message || 'Error'}`);
                        }
                    });
                    if (failedPlatforms.length > 0) {
                        errorMessage = failedPlatforms.join(', ');
                    }
                } else if (result.message) {
                    errorMessage = result.message;
                }
                
                message += `\n• Post ${result.post_id}: ${errorMessage}`;
            });
        }
        
        this.showNotice(message, successCount === totalCount ? 'success' : 'warning');
    }

    showNotice(message, type = 'info') {
        // Convert newlines to <br> for HTML display
        const htmlMessage = message.replace(/\n/g, '<br>');
        
        const noticeEl = document.createElement('div');
        noticeEl.className = `notice notice-${type} is-dismissible`;
        noticeEl.innerHTML = `<p>${htmlMessage}</p>`;
        
        const wrap = document.querySelector('.wrap');
        if (wrap) {
            wrap.insertBefore(noticeEl, wrap.firstChild);
        }

        // Auto-dismiss after 10 seconds for warnings/errors (more time to read)
        const dismissTime = type === 'success' ? 5000 : 10000;
        setTimeout(() => {
            if (noticeEl.parentNode) {
                noticeEl.remove();
            }
        }, dismissTime);
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('hashposter-posts-list') || document.querySelector('.hashposter-bulk-container')) {
        new HashPosterBulkPosting();
    }
});