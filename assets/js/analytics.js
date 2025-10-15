/**
 * HashPoster Analytics JavaScript
 *
 * Handles analytics dashboard functionality
 */

(function($) {
    'use strict';

    const HashPosterAnalytics = {

        charts: {},

        init: function() {
            this.loadAnalyticsData();
            this.bindEvents();
        },

        bindEvents: function() {
            // Date range change
            $('#date_from, #date_to').on('change', function() {
                HashPosterAnalytics.loadAnalyticsData();
            });

            // Update engagement data button
            $('#hashposter-update-engagement').on('click', function(e) {
                e.preventDefault();
                HashPosterAnalytics.updateEngagementData();
            });
        },

        loadAnalyticsData: function() {
            const dateFrom = $('#date_from').val() || hashposterAnalytics.date_from;
            const dateTo = $('#date_to').val() || hashposterAnalytics.date_to;

            // Show loading
            this.showLoading();

            $.ajax({
                url: hashposterAnalytics.ajax_url,
                type: 'POST',
                data: {
                    action: 'hashposter_get_analytics_data',
                    date_from: dateFrom,
                    date_to: dateTo,
                    nonce: hashposterAnalytics.nonce
                },
                success: function(response) {
                    if (response.success) {
                        HashPosterAnalytics.updateDashboard(response.data);
                    } else {
                        HashPosterAnalytics.showError(response.data.message || 'Failed to load analytics data');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Analytics Error:', error);
                    HashPosterAnalytics.showError('Failed to load analytics data');
                }
            });
        },

        updateEngagementData: function() {
            const $button = $('#hashposter-update-engagement');
            const originalText = $button.text();

            // Disable button and show loading
            $button.prop('disabled', true)
                   .text('Updating...')
                   .addClass('updating');

            $.ajax({
                url: hashposterAnalytics.ajax_url,
                type: 'POST',
                data: {
                    action: 'hashposter_update_engagement',
                    nonce: hashposterAnalytics.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        const message = response.data.message || 'Engagement data updated successfully!';
                        HashPosterAnalytics.showSuccess(message);
                        
                        // Reload analytics data to show updated stats
                        HashPosterAnalytics.loadAnalyticsData();
                    } else {
                        HashPosterAnalytics.showError(response.data.message || 'Failed to update engagement data');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Update Error:', error);
                    HashPosterAnalytics.showError('Failed to update engagement data');
                },
                complete: function() {
                    // Re-enable button
                    $button.prop('disabled', false)
                           .text(originalText)
                           .removeClass('updating');
                }
            });
        },

        updateDashboard: function(data) {
            // Update stats cards
            this.updateStatsCards(data);

            // Update charts
            this.updateCharts(data);

            // Update recent activity
            this.updateRecentActivity(data.recent_activity || []);

            // Update top posts
            this.updateTopPosts(data.top_posts || []);
        },

        updateStatsCards: function(data) {
            $('#total-posts').text(data.total_posts || 0);
            $('#successful-posts').text(data.successful_posts || 0);
            $('#failed-posts').text(data.failed_posts || 0);

            // Success rate
            const successRate = data.success_rate || 0;
            $('#success-rate').text(successRate + '%');

            // Most active platform
            if (data.most_active_platform) {
                $('#most-active-platform').text(this.formatPlatformName(data.most_active_platform.platform));
                $('#platform-count').text(data.most_active_platform.count + ' posts');
            } else {
                $('#most-active-platform').text('N/A');
                $('#platform-count').text('0 posts');
            }
        },

        updateCharts: function(data) {
            this.updatePostsOverTimeChart(data.posts_over_time || []);
            this.updatePlatformPerformanceChart(data.platform_data || []);
        },

        updatePostsOverTimeChart: function(postsData) {
            const ctx = document.getElementById('posts-over-time-chart');
            if (!ctx) return;

            // Destroy existing chart
            if (this.charts.postsOverTime) {
                this.charts.postsOverTime.destroy();
            }

            const labels = postsData.map(item => item.date);
            const values = postsData.map(item => parseInt(item.count));

            this.charts.postsOverTime = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Posts',
                        data: values,
                        borderColor: '#007cba',
                        backgroundColor: 'rgba(0, 124, 186, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        },

        updatePlatformPerformanceChart: function(platformData) {
            const ctx = document.getElementById('platform-performance-chart');
            if (!ctx) return;

            // Destroy existing chart
            if (this.charts.platformPerformance) {
                this.charts.platformPerformance.destroy();
            }

            // Group data by platform
            const platformStats = {};
            platformData.forEach(item => {
                if (!platformStats[item.platform]) {
                    platformStats[item.platform] = { success: 0, error: 0 };
                }
                platformStats[item.platform][item.status] = parseInt(item.count);
            });

            const labels = Object.keys(platformStats);
            const successData = labels.map(platform => platformStats[platform].success);
            const errorData = labels.map(platform => platformStats[platform].error);

            this.charts.platformPerformance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels.map(platform => this.formatPlatformName(platform)),
                    datasets: [{
                        label: 'Successful',
                        data: successData,
                        backgroundColor: '#46b450'
                    }, {
                        label: 'Failed',
                        data: errorData,
                        backgroundColor: '#dc3232'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        },

        updateRecentActivity: function(activity) {
            const container = $('#hashposter-activity-list');

            if (!activity.length) {
                container.html('<p>' + hashposterAnalytics.strings.no_data + '</p>');
                return;
            }

            let html = '';
            activity.forEach(item => {
                const statusClass = item.status === 'success' ? 'success' : 'error';
                const statusText = item.status === 'success' ? 'Success' : 'Error';
                const postTitle = item.post_title || 'Post #' + item.post_id;

                html += `
                    <div class="hashposter-activity-item">
                        <div class="hashposter-activity-info">
                            <strong>${this.escapeHtml(postTitle)}</strong>
                            <div class="hashposter-activity-meta">
                                ${this.formatPlatformName(item.platform)} • ${this.formatDate(item.published_at)}
                            </div>
                        </div>
                        <div class="hashposter-activity-status ${statusClass}">${statusText}</div>
                    </div>
                `;
            });

            container.html(html);
        },

        updateTopPosts: function(topPosts) {
            const container = $('#hashposter-top-posts-list');

            if (!topPosts.length) {
                container.html('<p>' + hashposterAnalytics.strings.no_data + '</p>');
                return;
            }

            let html = '';
            topPosts.forEach(post => {
                const engagementScore = parseFloat(post.engagement_score) || 0;
                const clicks = parseInt(post.clicks) || 0;
                const likes = parseInt(post.likes) || 0;
                const shares = parseInt(post.shares) || 0;
                const comments = parseInt(post.comments) || 0;

                html += `
                    <div class="hashposter-top-post-item">
                        <div class="hashposter-post-info">
                            <strong>${this.escapeHtml(post.post_title || 'Untitled Post')}</strong>
                            <div class="hashposter-post-meta">
                                ${this.formatPlatformName(post.platform)} • ${this.formatDate(post.published_at)}
                            </div>
                            <div class="hashposter-engagement-metrics">
                                <span class="metric-item"><i class="dashicons dashicons-external"></i> ${clicks} clicks</span>
                                <span class="metric-item"><i class="dashicons dashicons-heart"></i> ${likes} likes</span>
                                <span class="metric-item"><i class="dashicons dashicons-share"></i> ${shares} shares</span>
                                <span class="metric-item"><i class="dashicons dashicons-admin-comments"></i> ${comments} comments</span>
                            </div>
                        </div>
                        <div class="hashposter-post-stats">
                            <div class="engagement-score">${engagementScore.toFixed(1)}</div>
                            <div class="score-label">Engagement Score</div>
                        </div>
                    </div>
                `;
            });

            container.html(html);
        },

        formatPlatformName: function(platform) {
            const names = {
                'x': 'X (Twitter)',
                'facebook': 'Facebook',
                'linkedin': 'LinkedIn',
                'bluesky': 'Bluesky',
                'wordpress': 'WordPress'
            };
            return names[platform] || platform;
        },

        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        showLoading: function() {
            $('.hashposter-stat-number, .hashposter-stat-text').text('-');
            $('#hashposter-activity-list, #hashposter-top-posts-list').html('<p>' + hashposterAnalytics.strings.loading + '</p>');
        },

        showError: function(message) {
            $('.hashposter-stat-number, .hashposter-stat-text').text('Error');
            $('#hashposter-activity-list, #hashposter-top-posts-list').html('<p style="color: red;">' + message + '</p>');
            this.showNotification(message, 'error');
        },

        showSuccess: function(message) {
            this.showNotification(message, 'success');
        },

        showNotification: function(message, type) {
            // Create notification element
            const $notification = $('<div class="hashposter-notification ' + type + '">' + message + '</div>');
            
            // Add to body
            $('body').append($notification);
            
            // Fade in
            setTimeout(function() {
                $notification.addClass('show');
            }, 10);
            
            // Auto-dismiss after 3 seconds
            setTimeout(function() {
                $notification.removeClass('show');
                setTimeout(function() {
                    $notification.remove();
                }, 300);
            }, 3000);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('#hashposter-analytics-page').length || $('.hashposter-analytics-dashboard').length) {
            HashPosterAnalytics.init();
        }
    });

})(jQuery);