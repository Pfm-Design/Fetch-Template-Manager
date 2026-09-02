<?php
class FetchTemplatesExtension extends Minz_Extension {
    private static $domCache = [];

    public function init() {
        $this->registerHook('feed_before_insert', array($this, 'applyFeedSettings'));
        $this->registerHook('entry_before_insert', array($this, 'applyXPathParsing'));
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            $this->setSystemConfiguration('fetch_templates', Minz_Request::param('templates', '[]'));
            Minz_Session::_param('notification', 'Templates, Metadata, and Image Rules updated.');
        }
    }

    public function applyFeedSettings($feed) {
        $templates = json_decode($this->getSystemConfiguration('fetch_templates', '[]'), true);
        $feedUrl = strtolower($feed->url());

        foreach ($templates as $template) {
            if (in_array($feedUrl, $template['assigned_feeds']) || (!empty($template['match_url']) && strpos($feedUrl, $template['match_url']) !== false)) {
                // Manually override the parent Channel Description if defined in GUI
                if (!empty($template['manual_feed_desc'])) {
                    $feed->_description($template['manual_feed_desc']);
                }
                
                // Fetch retention behaviors
                if ($template['type'] === 'youtube') {
                    $feed->_keepHistory(100); 
                    $feed->_ttl(3600); 
                } else {
                    $feed->_keepHistory(Minz_ModelPdo::KEEP_ALL); 
                    $feed->_ttl(7200); 
                }
            }
        }
        return $feed;
    }

    public function applyXPathParsing($entry) {
        $templates = json_decode($this->getSystemConfiguration('fetch_templates', '[]'), true);
        $feedUrl = strtolower($entry->feed()->url());
        
        foreach ($templates as $template) {
            if (in_array($feedUrl, $template['assigned_feeds']) || (!empty($template['match_url']) && strpos($feedUrl, $template['match_url']) !== false)) {
                
                if (!isset(self::$domCache[$feedUrl])) {
                    $dom = new DOMDocument();
                    @$dom->load($feedUrl);
                    $xpath = new DOMXPath($dom);
                    
                    // Namespaces exactly as mapped in the "RSS" file
                    $xpath->registerNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
                    $xpath->registerNamespace('podcast', 'https://podcastindex.org/namespace/1.0');
                    $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
                    $xpath->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
                    $xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');
                    $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
                    
                    self::$domCache[$feedUrl] = $xpath;
                }
                
                $xpath = self::$domCache[$feedUrl];
                $entryGuid = $entry->guid();
                $imageUrl = '';
                $appendedContent = '';

                // 1. Image Extraction Hierarchy
                if (!empty($template['custom_image_xpath'])) {
                    $dynamicPath = str_replace('{guid}', $entryGuid, $template['custom_image_xpath']);
                    $imageUrl = $xpath->evaluate("string(" . $dynamicPath . ")");
                }

                if (!$imageUrl) {
                    if ($template['type'] === 'youtube') {
                        $imageUrl = $xpath->evaluate("string(//*[local-name()='entry'][*[local-name()='id']='$entryGuid']//media:thumbnail/@url)");
                    } elseif (in_array($template['type'], ['anchor', 'redcircle'])) {
                        $imageUrl = $xpath->evaluate("string(//item[guid='$entryGuid']/itunes:image/@href)");
                        if (!$imageUrl) {
                            $imageUrl = $xpath->evaluate("string(//channel/itunes:image/@href)");
                        }
                    }
                }

                if (!$imageUrl && !empty($template['default_image_url'])) {
                    $imageUrl = $template['default_image_url'];
                }

                if ($imageUrl) {
                    $entry->_thumbnail($imageUrl);
                }

                // 2. Content & Description Overrides
                if (!empty($template['manual_ep_desc'])) {
                    $appendedContent .= '<div class="custom-override-desc" style="padding: 10px; background: #f0f8ff; border-left: 4px solid #005A9C;">' . nl2br(htmlspecialchars($template['manual_ep_desc'])) . '</div><hr>';
                }

                if ($template['type'] === 'anchor') {
                    $transcriptUrl = $xpath->evaluate("string(//item[guid='$entryGuid']/podcast:transcript/@url)");
                    $episodeType = $xpath->evaluate("string(//item[guid='$entryGuid']/itunes:episodeType)");
                    $duration = $xpath->evaluate("string(//item[guid='$entryGuid']/itunes:duration)");
                    $nativeDesc = $xpath->evaluate("string(//item[guid='$entryGuid']/description)");
                    
                    $appendedContent .= '<div class="anchor-meta"><strong>Type:</strong> ' . ($episodeType ?: 'full') . ' | <strong>Duration:</strong> ' . $duration . '</div>';
                    if ($transcriptUrl) $appendedContent .= '<a href="' . $transcriptUrl . '">Download Transcript</a><br>';
                    
                    $appendedContent .= empty($template['manual_ep_desc']) ? $nativeDesc : '';

                } elseif ($template['type'] === 'redcircle') {
                    $transcriptUrl = $xpath->evaluate("string(//item[guid='$entryGuid']/podcast:transcript/@url)");
                    $contentEncoded = $xpath->evaluate("string(//item[guid='$entryGuid']/content:encoded)");
                    
                    $appendedContent .= '<div class="redcircle-meta">';
                    if ($transcriptUrl) $appendedContent .= '<a href="' . $transcriptUrl . '">Download Transcript</a><br>';
                    $appendedContent .= '</div>';
                    
                    $appendedContent .= empty($template['manual_ep_desc']) ? $contentEncoded : '';

                } elseif ($template['type'] === 'youtube') {
                    $videoId = $xpath->evaluate("string(//*[local-name()='entry'][*[local-name()='id']='$entryGuid']/yt:videoId)");
                    $mediaDesc = $xpath->evaluate("string(//*[local-name()='entry'][*[local-name()='id']='$entryGuid']//media:group/media:description)");
                    
                    if ($videoId) {
                        $appendedContent .= '<iframe width="560" height="315" src="https://www.youtube.com/embed/' . $videoId . '" frameborder="0" allowfullscreen></iframe><br>';
                    }
                    $appendedContent .= empty($template['manual_ep_desc']) ? nl2br(htmlspecialchars($mediaDesc)) : '';
                }

                $entry->_content($appendedContent);
                
                // 3. Automation Metadata Tags Injection
                $systemFlag = ($template['type'] === 'youtube') ? 'VIDEO_SYNC' : 'AUDIO_SYNC';
                $automationId = hash('crc32', $template['type'] . '_' . $entryGuid);
                
                $tags = $entry->tags();
                if (!empty($template['custom_tag'])) $tags[] = $template['custom_tag'];
                $tags[] = 'sys_flag:' . $systemFlag;
                $tags[] = 'automation_id:' . $automationId;
                $tags[] = 'source_guid:' . $entryGuid;
                
                $entry->_tags(array_unique($tags));
            }
        }
        return $entry;
    }
}
