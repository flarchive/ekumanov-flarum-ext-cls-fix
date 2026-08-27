<?php

namespace Ekumanov\ClsFix;

use s9e\TextFormatter\Configurator;

class ConfigureFormatter
{
    public function __invoke(Configurator $config): void
    {
        if (! isset($config->tags['IMG'])) {
            return;
        }

        // Wraps every inline image in a placeholder <span> with a reserved
        // aspect ratio. Three sources of truth, in priority order:
        //   1. @width and @height attrs present (BBCode-supplied OR injected by
        //      InjectImageDimensions from the cache) → exact ratio, zero CLS.
        //   2. Otherwise → 16/9 default placeholder + data-cls-needs-dims="1"
        //      flag so the client JS reports natural dims to the server after
        //      the image loads. Subsequent visitors hit case 1.
        $config->tags['IMG']->template = <<<'XSL'
<span class="cls-img-wrap">
    <xsl:attribute name="style">
        <xsl:choose>
            <xsl:when test="@width and @height and number(@width) &gt; 0 and number(@height) &gt; 0">aspect-ratio: <xsl:value-of select="@width"/> / <xsl:value-of select="@height"/>; --cls-img-natural-width: <xsl:value-of select="@width"/>px;</xsl:when>
            <xsl:otherwise>aspect-ratio: var(--cls-img-ratio, 16 / 9);</xsl:otherwise>
        </xsl:choose>
    </xsl:attribute>
    <xsl:if test="not(@width) or not(@height) or number(@width) = 0 or number(@height) = 0">
        <xsl:attribute name="data-cls-needs-dims">1</xsl:attribute>
    </xsl:if>
    <img class="cls-img" loading="lazy">
        <xsl:attribute name="src"><xsl:value-of select="@src"/></xsl:attribute>
        <xsl:if test="@alt">
            <xsl:attribute name="alt"><xsl:value-of select="@alt"/></xsl:attribute>
        </xsl:if>
        <xsl:if test="@title">
            <xsl:attribute name="title"><xsl:value-of select="@title"/></xsl:attribute>
        </xsl:if>
        <xsl:if test="@width and number(@width) &gt; 0">
            <xsl:attribute name="width"><xsl:value-of select="@width"/></xsl:attribute>
        </xsl:if>
        <xsl:if test="@height and number(@height) &gt; 0">
            <xsl:attribute name="height"><xsl:value-of select="@height"/></xsl:attribute>
        </xsl:if>
    </img>
</span>
XSL;
    }
}
