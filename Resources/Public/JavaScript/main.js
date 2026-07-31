/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

/**
 * MarkFlaggedPageInLayoutModule embeds a JSON map of tt_content uid -> badge HTML
 * for every flagged content element on the current page (already loaded server-side
 * via AiMetadataRecordFinder, no extra request here). There is no PSR-14 event to
 * render into a content element's own header-right button group directly (only
 * EXT:backend's own hardcoded partial builds that markup), so this injects each
 * badge into the matching element's button group client-side instead.
 */
class AiLabelPageModule {
	constructor() {
		this.injectContentBadges();
	}

	injectContentBadges() {
		const dataElement = document.getElementById('ai-label-content-badges');
		if (!dataElement) {
			return;
		}

		let badges;
		try {
			badges = JSON.parse(dataElement.textContent);
		} catch (e) {
			return;
		}

		Object.entries(badges).forEach(([uid, badgeHtml]) => {
			const contentElement = document.querySelector(`.t3-page-ce[data-table="tt_content"][data-uid="${uid}"]`);
			if (!contentElement) {
				return;
			}
			const buttonGroup = contentElement.querySelector('.t3-page-ce-header-right .btn-group');
			if (!buttonGroup) {
				return;
			}
			const marker = document.createElement('span');
			marker.className = 'ai-label-marker';
			marker.innerHTML = badgeHtml;
			buttonGroup.prepend(marker);
		});
	}
}

export default new AiLabelPageModule();
