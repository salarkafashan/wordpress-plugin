/* global KGR_CONFIG */
( function () {
	'use strict';

	window.KGR_ISSUE_TYPES = {
		CONTENT: 'Content change',
		IMAGE: 'Image replacement',
		FORM: 'Form problem',
		PERFORMANCE: 'Performance issue',
		OTHER: 'Other',
	};
	
	window.kgrData = function() {
		return {
			service_type: '',
			website_url: '',
			email: '',
			first_name: '',
			last_name: '',
			business_name: '',
			title: '',
			message: '',
			attachments: [],
			issues: [],
			step_index: 0,
			// Helpers to avoid WordPress smart quote issues in HTML expressions
			shouldShowContact() {
				return this.step_index >= 2;
			},
			shouldShowIssues() {
				return this.step_index >= 3;
			},
			isWebsite() {
				return this.service_type === 'Website';
			},
			isNotWebsite() {
				return this.service_type !== 'Website' && this.service_type !== '';
			},
			hasService() {
				return this.service_type !== '';
			},
			getPreviewMessage() {
				if (!this.message) return '';
				return this.message.substring(0, 50) + (this.message.length > 50 ? '...' : '');
			},
			getScreenshotCount(issue) {
				return (issue.screenshots || []).length;
			},
			hasScreenshots(issue) {
				return (issue.screenshots || []).length > 0;
			},
			getScreenshotLabel(issue) {
				const count = (issue.screenshots || []).length;
				return count + (count === 1 ? ' screenshot attached' : ' screenshots attached');
			},
			getIssueProblem(issue) {
				return issue.description || '';
			},
			getAttachmentsLabel() {
				const count = (this.attachments || []).length;
				return count + (count === 1 ? ' file' : ' files');
			}
		};
	};

}() );
