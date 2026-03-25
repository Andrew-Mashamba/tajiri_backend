<?xml version="1.0" encoding="utf-8" ?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
<xsl:output method="html"/>
<xsl:template match="/">
<html>
<head>
    <title>TAJIRI RTMP Statistics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d4ff; margin-bottom: 20px; }
        h2 { color: #ff6b6b; border-bottom: 1px solid #333; padding-bottom: 10px; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; background: #16213e; border-radius: 8px; overflow: hidden; }
        th { background: #0f3460; color: #00d4ff; padding: 12px; text-align: left; }
        td { padding: 10px 12px; border-bottom: 1px solid #333; }
        tr:hover { background: #1f4068; }
        .live { color: #4ade80; font-weight: bold; }
        .offline { color: #888; }
        .stat-card { display: inline-block; background: #0f3460; padding: 15px 25px; margin: 5px; border-radius: 8px; }
        .stat-value { font-size: 24px; font-weight: bold; color: #00d4ff; }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; }
    </style>
</head>
<body>
    <h1>🎥 TAJIRI RTMP Server Statistics</h1>

    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-value"><xsl:value-of select="count(//stream)"/></div>
            <div class="stat-label">Total Streams</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><xsl:value-of select="count(//stream[publishing])"/></div>
            <div class="stat-label">Live Now</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><xsl:value-of select="sum(//stream/nclients)"/></div>
            <div class="stat-label">Total Clients</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><xsl:value-of select="format-number(sum(//stream/bw_in) div 1048576, '0.00')"/> Mbps</div>
            <div class="stat-label">Bandwidth In</div>
        </div>
    </div>

    <xsl:for-each select="rtmp/server">
    <h2>Server: <xsl:value-of select="@name"/></h2>

    <xsl:for-each select="application">
    <h3 style="color: #ffd93d;">Application: <xsl:value-of select="name"/></h3>

    <xsl:if test="live/stream">
    <table>
        <tr>
            <th>Stream Key</th>
            <th>Status</th>
            <th>Clients</th>
            <th>Video</th>
            <th>Audio</th>
            <th>Bandwidth In</th>
            <th>Bandwidth Out</th>
            <th>Time</th>
        </tr>
        <xsl:for-each select="live/stream">
        <tr>
            <td><xsl:value-of select="name"/></td>
            <td>
                <xsl:choose>
                    <xsl:when test="publishing"><span class="live">● LIVE</span></xsl:when>
                    <xsl:otherwise><span class="offline">○ Offline</span></xsl:otherwise>
                </xsl:choose>
            </td>
            <td><xsl:value-of select="nclients"/></td>
            <td><xsl:value-of select="meta/video/codec"/> <xsl:value-of select="meta/video/width"/>x<xsl:value-of select="meta/video/height"/> @<xsl:value-of select="meta/video/frame_rate"/>fps</td>
            <td><xsl:value-of select="meta/audio/codec"/> <xsl:value-of select="meta/audio/sample_rate"/>Hz</td>
            <td><xsl:value-of select="format-number(bw_in div 1024, '0')"/> Kbps</td>
            <td><xsl:value-of select="format-number(bw_out div 1024, '0')"/> Kbps</td>
            <td><xsl:value-of select="format-number(time div 1000, '0')"/>s</td>
        </tr>
        </xsl:for-each>
    </table>
    </xsl:if>

    <xsl:if test="not(live/stream)">
    <p style="color: #888; font-style: italic;">No active streams in this application.</p>
    </xsl:if>

    </xsl:for-each>
    </xsl:for-each>

    <p style="color: #666; margin-top: 30px; font-size: 12px;">
        Auto-refresh: 3 seconds | Generated: <xsl:value-of select="rtmp/uptime"/> seconds uptime
    </p>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
