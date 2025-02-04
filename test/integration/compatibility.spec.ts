import { Page, expect, test } from '@playwright/test';
import { clearMediaLibrary, enableCompressionSizes, getWPVersion, setAPIKey, setCompressionTiming, setOriginalImage, uploadMedia } from './utils';

test.describe.configure({ mode: 'serial' });

let page: Page;
let WPVersion = 0;

test.describe('woocommerce', () => {
    
});
