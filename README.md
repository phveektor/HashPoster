# HashPoster - Social Media Autoposter for WordPress

![HashPoster](assets/images/hashposter-banner.png)

HashPoster is a lightweight, powerful WordPress plugin that automatically shares your posts to multiple social media platforms with customizable content and media integration.

[![WordPress Compatible](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/plugins/hashposter/)
[![License](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## 🚀 Features

- **Multiple Platforms Support**: X (Twitter), Facebook, LinkedIn (business pages), Bluesky, and Reddit
- **Automatic Open Graph & Twitter Cards**: Optimizes your content for social sharing automatically
- **Media Sharing**: Attaches featured images as cards or uploads them directly to social platforms
- **URL Shortening**: Bitly integration and WordPress shortlinks support
- **Flexible Post Templates**: Customize your social media posts with various templates tags
- **Scheduling**: Delay posting to avoid flooding your followers' feeds
- **Secure Credential Management**: All API keys and tokens are stored securely
- **Developer Friendly**: Well-documented code with filters for customization

## 📥 Installation

### Automatic Installation

1. Log in to your WordPress dashboard
2. Go to Plugins > Add New
3. Search for "HashPoster"
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Download the plugin zip file
2. Log in to your WordPress dashboard
3. Go to Plugins > Add New > Upload Plugin
4. Choose the downloaded zip file and click "Install Now"
5. Activate the plugin

## ⚙️ Configuration

### General Settings

1. Go to Settings > HashPoster in your WordPress admin
2. Enable the plugin and select your desired settings

### Platform Setup

#### X (Twitter)
1. Create a [Twitter Developer account](https://developer.twitter.com/)
2. Create an application with Read & Write permissions
3. Generate API Key, API Secret, Access Token, and Access Token Secret
4. Enter these credentials in the HashPoster Twitter settings

#### Facebook
1. Create a [Facebook Developer account](https://developers.facebook.com/)
2. Create an app and set up a Page access token
3. Enter your Page ID and Access Token in the HashPoster Facebook settings

#### LinkedIn
1. Create a [LinkedIn Developer account](https://www.linkedin.com/developers/)
2. Create an application with `r_organization_social` and `w_member_social` scopes
3. Generate an access token
4. Use the built-in URN Helper to find your Organization URN
5. Enter these details in the HashPoster LinkedIn settings

#### Bluesky
1. Log in to your Bluesky account
2. Create an App Password in your account settings
3. Enter your handle and App Password in the HashPoster Bluesky settings

#### Reddit
1. Create a [Reddit Developer account](https://www.reddit.com/prefs/apps)
2. Create a script-type application
3. Enter your Client ID, Client Secret, username, password, and target subreddit in the HashPoster Reddit settings

### Post Card Configuration

Customize how your content appears on social media with template tags:
- `{title}` - Post title
- `{url}` - Full post URL
- `{short_url}` - Shortened URL (Bitly or WordPress)
- `{excerpt}` - Post excerpt
- `{author}` - Post author name
- `{date}` - Post publication date
- `{category}` - Primary category
- `{tags}` - Post tags
- `{site_name}` - Your website name

Example template: `Check out my new post: {title} {short_url} #wordpress #{category}`

### URL Shortening

HashPoster provides two options for URL shortening:
1. **WordPress Shortlinks**: Uses WordPress' built-in shortlink feature
2. **Bitly**: Connects to Bitly for branded short links (requires API token)

## 🔄 Workflow

1. Write and publish a post in WordPress
2. HashPoster automatically formats the content based on your template
3. Featured images are attached to your social posts where supported
4. Content is posted to your configured social platforms
5. Track successful posts in the post meta

## 💡 Advanced Tips

### Custom Hooks & Filters

Developers can use these hooks to customize the plugin:
- `hashposter_post_content` - Modify the content before posting
- `hashposter_platforms` - Filter which platforms to post to
- `hashposter_post_data` - Modify post data before processing

### Scheduling

Set a delay (in minutes) to schedule your social posts after publication:
1. Enable scheduling in Settings > HashPoster > Scheduling
2. Set your preferred delay time
3. When a post is published, social sharing will be queued accordingly

## 🔧 Troubleshooting

### Common Issues

1. **Posts not showing up on social platforms**
   - Check your API credentials for each platform
   - Ensure your tokens haven't expired
   - Verify permissions for each platform

2. **Featured images not appearing**
   - Make sure posts have featured images set
   - Check that your images meet platform requirements (size, format)

3. **Shortlinks not generating**
   - Verify your Bitly token is valid
   - Check WordPress shortlink functionality

### Debugging

Enable debugging in the plugin settings to log activities to the WordPress debug log.

## 📚 Changelog

### 1.0 - Initial Release
- Multiple platform support
- Customizable post templates
- Featured image sharing
- Shortlink integration
- Scheduling capabilities

## 🔒 Privacy & Security

HashPoster:
- Stores API credentials securely in your WordPress database
- Does not collect usage data
- Does not send data to third parties except the configured social platforms
- Requires minimal permissions for each platform

## 🙋 Support & Contributions

For support, feature requests, or bug reports:

- GitHub Issues: [Report an issue](https://github.com/phveektor/hashposter/issues)
- Contact: [support@hashposter.com](mailto:support@hashposter.com)

Contributions welcome! Please read our [contribution guidelines](CONTRIBUTING.md).

## 📄 License

HashPoster is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
