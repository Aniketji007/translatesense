import { translateBulkTerm, updateBulkJob } from '../services/bulkService';
import { getBatchStatus } from '../services/batchService';
import { extractStrings, translatePost } from '../../services/core/translationService';
import { getCoreInstance } from '../../services/core/coreService';
import { isEmailOrUrl } from '../../utils/validation';
import { getPostProgress, getOverallProgress } from '../utils/bulkCalculations';

/**
 * Checks if a queued translation job has expired (more than 24 hours old).
 *
 * @param {Object} job Translation job object.
 * @return {boolean} True if job is expired, false otherwise.
 */
function isTranslationJobExpired(job) {
  if (!job || !job.created_at) {
    return false;
  }
  const normalizedCreatedAt =
    typeof job.created_at === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(job.created_at)
      ? `${job.created_at.replace(' ', 'T')}Z`
      : job.created_at;
  const jobCreated = normalizedCreatedAt ? new Date(normalizedCreatedAt).getTime() : 0;
  return jobCreated > 0 && (Date.now() - jobCreated) >= 86400000;
}

/**
 * Validates provider registration, language support, and device availability.
 *
 * @param {Object} core Core translation instance.
 * @param {string} providerName Provider key (e.g. 'chrome_ai').
 * @param {string} sourceLanguage Source language code.
 * @param {string} targetLang Target language code.
 */
async function validateTranslationProvider(core, providerName, sourceLanguage, targetLang) {
  if (!core) {
    return;
  }
  const provider = typeof core.getProvider === 'function' ? core.getProvider(providerName) : null;
  if (!provider) {
    throw new Error('Built-in AI translation provider is not registered.');
  }

  if (
    typeof provider.isLanguageSupported === 'function' &&
    !provider.isLanguageSupported(sourceLanguage, targetLang)
  ) {
    const err = new Error(
      `Language pair (${sourceLanguage.toUpperCase()} → ${targetLang.toUpperCase()}) is not supported by ${provider.title || 'Built-in AI'}.`
    );
    err.errorObj = { reason: err.message };
    throw err;
  }

  if (typeof provider.checkAvailability === 'function') {
    const availability = await provider.checkAvailability(sourceLanguage, targetLang);
    if (availability && availability.available === false) {
      const reasonMsg =
        availability.reason ||
        `Language pair (${sourceLanguage.toUpperCase()} → ${targetLang.toUpperCase()}) is not supported by ${provider.title || 'Built-in AI'}.`;
      const err = new Error(reasonMsg);
      err.errorObj = availability;
      throw err;
    }
  }
}

/**
 * Extracts strings for an item or retrieves them from extraction cache.
 *
 * @param {string|number} postId Post or term ID.
 * @param {string} targetLang Target language code.
 * @param {Object} options Options containing type, subtype, isTaxonomy.
 * @param {Object} cache In-memory extraction cache object.
 * @return {Promise<Object>} Extracted strings and item metadata.
 */
async function extractTranslationStrings(postId, targetLang, options = {}, cache = {}) {
  const { type = 'post', subtype = '', isTaxonomy = false } = options;
  const extractionKey = `${postId}:${targetLang}`;

  let rawStrings = [];
  let sourceLanguage = 'en';
  let itemTaxonomy = isTaxonomy ? subtype : '';
  let itemIsTaxonomy = isTaxonomy;

  if (cache[extractionKey]) {
    rawStrings = cache[extractionKey].strings || [];
    sourceLanguage = cache[extractionKey].sourceLanguage || 'en';
    itemTaxonomy = cache[extractionKey].taxonomy || itemTaxonomy;
    itemIsTaxonomy = cache[extractionKey].isTaxonomy;
  } else {
    const extRes = await extractStrings(postId, '', targetLang, {
      type,
      subtype,
      includeTaxonomies: !isTaxonomy,
    });
    rawStrings = extRes.strings || [];
    sourceLanguage = extRes.source_language || 'en';
    itemIsTaxonomy = extRes.editor_type === 'taxonomy' || isTaxonomy;
    itemTaxonomy = extRes.taxonomy || itemTaxonomy;
    cache[extractionKey] = {
      strings: rawStrings,
      sourceLanguage,
      taxonomy: itemTaxonomy,
      isTaxonomy: itemIsTaxonomy,
    };
  }

  const translatable = rawStrings.filter(
    (s) => s.value && s.value.trim() && !isEmailOrUrl(s.value)
  );

  if (translatable.length === 0) {
    console.error(`No translatable text found in ${itemIsTaxonomy ? 'taxonomy term' : 'post'}.`);
  }

  return {
    rawStrings,
    translatable,
    sourceLanguage,
    itemTaxonomy,
    itemIsTaxonomy,
  };
}

/**
 * Formats translatable strings into items for core translation engine.
 *
 * @param {Array} translatable Translatable string objects.
 * @return {Array} Formatted items for translation.
 */
function buildTranslationItems(translatable = []) {
  return translatable.map((s) => ({
    hash: s.hash,
    widget_id: s.name,
    field: s.key,
    content: s.value,
  }));
}


/**
 * Creates the mutable progress model used while processing a runtime batch.
 * The jobs are intentionally processed sequentially to preserve queue ordering
 * and accurate progress updates.
 */
function createBulkRunState(jobsList = []) {
  let hasQueueJob = false;

  const runningJobs = jobsList.map((job) => {
    if (!hasQueueJob && job.status !== 'already_exists') {
      hasQueueJob = true;
    }

    return {
      id: job.id,
      post_id: job.post_id,
      target_language: job.target_language,
      status: job.status || 'pending',
      total_strings: Number(job.total_strings) || 0,
      completed_strings: Number(job.completed_strings) || 0,
    };
  });

  return { runningJobs, hasQueueJob, failedCount: 0, extractedCache: {} };
}

/**
 * Updates the UI and queue record when a queued job has expired.
 */
async function handleExpiredBulkJob(job, index, state, context) {
  const postId = String(job.post_id);
  const targetLang = String(job.target_language);
  const errorMessage = 'Translation job expired: queued for more than 1 day.';
  const progressStatus = 'Failed: Job expired (more than 1 day old)';

  state.failedCount += 1;
  state.runningJobs[index].status = 'failed';

  try {
    await updateBulkJob(
      job.id,
      {
        status: 'failed',
        progress_status: progressStatus,
        error_message: errorMessage,
      },
      context.itemType
    );
  } catch {
    // Queue synchronization is non-blocking; the local UI still reflects failure.
  }

  context.updatePostTranslation(postId, targetLang, {
    status: 'failed',
    error: errorMessage,
    progressPercent: 100,
    progressStatus,
    targetTitle: `Failed #${postId}`,
  });
}

/**
 * Executes a taxonomy job through the server-side provider.
 */
async function executeServerTaxonomyJob(job, jobContext) {
  const { postId, targetLang, postTitle, activeProvider, context, startJobPct } = jobContext;

  context.setStatusText(
    `Translating ${postTitle} (${targetLang.toUpperCase()})... (${startJobPct}%)`
  );

  const response = await translateBulkTerm({
    job_id: job.id,
    term_id: Number(postId),
    taxonomy: context.subtype,
    target_lang: targetLang,
    provider: activeProvider,
  });

  const totalStrings = Number(response.total_strings) || 0;
  const totalCharacters = Number(response.total_characters) || 0;

  return {
    response,
    rawStrings: [],
    totalStringsCount: totalStrings,
    completedStringsCount: totalStrings,
    totalCharacters,
    updateBulkJobData: {},
  };
}

/**
 * Executes a browser-AI translation after extracting and validating strings.
 * Saving remains delegated to the existing translation services.
 */
async function executeBrowserTranslation(job, jobContext) {
  const {
    postId,
    targetLang,
    postTitle,
    index,
    state,
    context,
    core,
    jobStartTime,
    startJobPct,
  } = jobContext;

  context.setStatusText(
    `Extracting strings for ${postTitle} (${targetLang.toUpperCase()})... (${startJobPct}%)`
  );

  if (!core) {
    throw new Error('Built-in AI translation is unavailable in this browser session.');
  }

  const extracted = await extractTranslationStrings(
    postId,
    targetLang,
    {
      type: context.itemType,
      subtype: context.subtype,
      isTaxonomy: context.isTaxonomy,
    },
    state.extractedCache
  );

  const {
    rawStrings,
    translatable,
    sourceLanguage,
    itemTaxonomy,
    itemIsTaxonomy,
  } = extracted;

  await validateTranslationProvider(core, 'chrome_ai', sourceLanguage, targetLang);

  const totalStrings = translatable.length;
  const totalCharacters = translatable.reduce(
    (total, item) => total + (item.value ? item.value.length : 0),
    0
  );

  let completedStrings = 0;
  let completedCharacters = 0;

  state.runningJobs[index].total_strings = totalStrings;
  state.runningJobs[index].completed_strings = 0;
  state.runningJobs[index].total_characters = totalCharacters;
  state.runningJobs[index].completed_characters = 0;
  state.runningJobs[index].status = 'running';

  const extractDonePct = Math.round(getOverallProgress(state.runningJobs));
  context.setProgressPercent(extractDonePct);
  context.setStatusText(
    `Translating ${totalStrings} strings on-device for ${postTitle} (${targetLang.toUpperCase()})... (${extractDonePct}%)`
  );

  context.updatePostTranslation(postId, targetLang, {
    status: 'running',
    totalStrings,
    completedStrings: 0,
    totalCharacters,
    completedCharacters: 0,
    progressPercent: getPostProgress(state.runningJobs[index]),
    progressStatus: `0/${totalStrings} strings translated`,
    targetTitle: `Translating 0/${totalStrings} strings...`,
  });

  const inMemoryTranslations = {};
  const itemsToTranslate = buildTranslationItems(translatable);

  // O(1) lookup for the original source string during progress callbacks.
  const stringMap = new Map();
  translatable.forEach((item) => {
    if (item && item.hash) {
      stringMap.set(item.hash, item);
    }
  });

  await core.translate(
    'chrome_ai',
    {
      items: itemsToTranslate,
      sourceLang: sourceLanguage,
      targetLang,
      postId,
    },
    (resultItem) => {
      if (resultItem && resultItem.translated && resultItem.hash) {
        inMemoryTranslations[resultItem.hash] = resultItem.translated;
      }

      completedStrings += 1;

      const original = resultItem?.hash ? stringMap.get(resultItem.hash) : null;
      if (original && original.value) {
        completedCharacters += original.value.length;
      } else if (resultItem && resultItem.source) {
        completedCharacters += resultItem.source.length;
      }

      state.runningJobs[index].completed_strings = completedStrings;
      state.runningJobs[index].total_strings = totalStrings;
      state.runningJobs[index].completed_characters = completedCharacters;
      state.runningJobs[index].total_characters = totalCharacters;
      state.runningJobs[index].status = 'running';

      const postPercent = getPostProgress(state.runningJobs[index]);
      const overallPercent = Math.round(getOverallProgress(state.runningJobs));

      context.setProgressPercent(overallPercent);
      context.setStatusText(
        `Translating on-device: ${postTitle} (${targetLang.toUpperCase()}) - string ${completedStrings} of ${totalStrings} (${overallPercent}%)`
      );

      context.updatePostTranslation(postId, targetLang, {
        status: 'running',
        completedStrings,
        totalStrings,
        completedCharacters,
        totalCharacters,
        progressPercent: postPercent,
        progressStatus: `Translating string ${completedStrings} of ${totalStrings} (${postPercent}%)`,
        targetTitle: `Translating string ${completedStrings} of ${totalStrings} (${postPercent}%)...`,
      });
    }
  );

  await new Promise((resolve) => setTimeout(resolve, 1500));

  const saveStatus = itemIsTaxonomy
    ? 'Saving translated term to WordPress...'
    : 'Saving translated post to WordPress...';

  context.setStatusText(`Saving ${postTitle} (${targetLang.toUpperCase()}) to WordPress...`);
  context.updatePostTranslation(postId, targetLang, {
    progressStatus: saveStatus,
    targetTitle: saveStatus,
  });

  const response = itemIsTaxonomy
    ? await translateBulkTerm({
      job_id: job.id,
      term_id: Number(postId),
      taxonomy: itemTaxonomy,
      target_lang: targetLang,
      provider: 'chrome_ai',
      in_memory_translations: inMemoryTranslations,
    })
    : await translatePost({
      post_id: postId,
      target_lang: targetLang,
      provider: 'chrome_ai',
      in_memory_translations: inMemoryTranslations,
      include_taxonomies: true,
    });

  return {
    response,
    rawStrings,
    totalStringsCount: rawStrings.length,
    completedStringsCount: rawStrings.length,
    totalCharacters,
    updateBulkJobData: {
      status: 'completed',
      progress_status: 'Completed successfully',
      total_strings: rawStrings.length,
      completed_strings: rawStrings.length,
      total_characters: totalCharacters,
      completed_characters: totalCharacters,
      time_take: Math.round(performance.now() - jobStartTime),
      target_post_id: response.target_post_id,
    },
  };
}

/**
 * Processes one non-expired job and returns the result used by the UI.
 */
async function processBulkTranslationJob(job, index, state, runOptions, context) {
  const {
    activeProvider,
    useBrowserAi,
    core,
  } = runOptions;

  const postId = String(job.post_id);
  const targetLang = String(job.target_language);
  const postTitle = job.post_title || `${context.itemTitlePrefix} #${postId}`;

  state.runningJobs[index].status = 'running';
  const startJobPct = Math.round(getOverallProgress(state.runningJobs));
  context.setProgressPercent(startJobPct);

  const initialProgressStatus =
    context.isTaxonomy && !useBrowserAi
      ? `Translating ${postTitle}...`
      : `Extracting strings for ${postTitle}...`;

  context.updatePostTranslation(postId, targetLang, {
    status: 'running',
    progressStatus: initialProgressStatus,
    targetTitle: initialProgressStatus,
    progressPercent: getPostProgress(state.runningJobs[index]),
  });

  const jobStartTime = performance.now();
  let rawStrings = [];
  const updatedPostResult = {};
  let updateBulkJobData = {};

  try {
    let jobResult;

    if (context.isTaxonomy && !useBrowserAi) {
      jobResult = await executeServerTaxonomyJob(job, {
        postId,
        targetLang,
        postTitle,
        activeProvider,
        startJobPct,
        context,
      });
    } else {
      jobResult = await executeBrowserTranslation(job, {
        postId,
        targetLang,
        postTitle,
        index,
        state,
        context,
        core,
        jobStartTime,
        startJobPct,
      });
    }

    rawStrings = jobResult.rawStrings || [];
    updateBulkJobData = jobResult.updateBulkJobData || {};

    const response = jobResult.response;
    const totalStringsCount = jobResult.totalStringsCount;
    const completedStringsCount = jobResult.completedStringsCount;
    const totalCharacters = jobResult.totalCharacters;

    state.runningJobs[index].status = 'completed';
    state.runningJobs[index].completed_strings = completedStringsCount;
    state.runningJobs[index].total_strings = totalStringsCount;
    state.runningJobs[index].completed_characters = totalCharacters;
    state.runningJobs[index].total_characters = totalCharacters;

    updatedPostResult.status = 'completed';
    updatedPostResult.targetPostId = response.target_post_id;
    updatedPostResult.targetTitle = response.translated_title || postTitle;
    updatedPostResult.editUrl = response.edit_url;
    updatedPostResult.viewUrl = response.view_url;
    updatedPostResult.completedStrings = completedStringsCount;
    updatedPostResult.totalStrings = totalStringsCount;
    updatedPostResult.completedCharacters = totalCharacters;
    updatedPostResult.totalCharacters = totalCharacters;
    updatedPostResult.progressPercent = 100;
    updatedPostResult.progressStatus = 'Completed successfully';
  } catch (error) {
    state.failedCount += 1;
    state.runningJobs[index].status = 'failed';

    const errorMessage = error?.message || 'Translation failed.';

    updateBulkJobData = {
      status: 'failed',
      progress_status: `Failed: ${errorMessage}`,
      error_message: errorMessage,
      total_strings: rawStrings.length || 0,
      time_take: Math.round(performance.now() - jobStartTime),
    };

    updatedPostResult.status = 'failed';
    updatedPostResult.error = errorMessage;
    updatedPostResult.errorObj = error.errorObj || null;
    updatedPostResult.progressPercent = 100;
    updatedPostResult.progressStatus = `Failed: ${errorMessage}`;
    updatedPostResult.targetTitle = `Failed #${postId}`;
  }

  // Keep the original behavior: browser-AI jobs are synchronized to the queue;
  // server-side taxonomy jobs are completed by translateBulkTerm itself.
  if (useBrowserAi) {
    try {
      await updateBulkJob(job.id, updateBulkJobData, context.itemType);
    } catch {
      // Non-blocking queue progress synchronization.
    }
  }

  const postFinishPct = Math.round(getOverallProgress(state.runningJobs));
  context.setProgressPercent(postFinishPct);
  context.updatePostTranslation(postId, targetLang, updatedPostResult);
}

/**
 * Refreshes the batch and marks the runtime workflow as complete.
 */
async function finalizeBulkTranslation(state, batchId, context) {
  const hasRunnableJobs = state.runningJobs.some(
    (job) => job.status === 'completed' || job.status === 'failed'
  );

  context.setProgressPercent(100);
  context.setStatusText(
    state.failedCount > 0
      ? `Completed with ${state.failedCount} errors.`
      : hasRunnableJobs
        ? 'All translations completed successfully!'
        : 'All selected translations already exist.'
  );
  context.setIsTranslating(false);
  context.stopTimer();

  if (state.hasQueueJob && batchId) {
    try {
      const freshBatch = await getBatchStatus(batchId);
      if (freshBatch && Array.isArray(freshBatch.items) && freshBatch.items.length > 0) {
        context.populateMapFromBatch(freshBatch, false);
        context.setTotalJobsCount(freshBatch.total_jobs || freshBatch.items.length);
      }
    } catch {
      // The locally tracked job results remain available if refresh fails.
    }
  }

  await new Promise((resolve) => setTimeout(resolve, 3000));
  context.setIsCompleted(true);
}

/**
 * Main runtime workflow. This function intentionally processes jobs one at a
 * time because each completion updates the shared progress model and queue.
 */
export async function runBulkTranslationWorkflow(jobsList = [], batchId, activeProvider, context) {
  const normalizedJobs = Array.isArray(jobsList) ? jobsList : [];
  const useBrowserAi = activeProvider === 'chrome_ai';
  const core = useBrowserAi ? getCoreInstance() : null;
  const state = createBulkRunState(normalizedJobs);
  const totalJobs = normalizedJobs.length || 1;

  context.setTotalJobsCount(totalJobs);

  const initialPercent = Math.round(getOverallProgress(state.runningJobs));
  const runtimeLabel = useBrowserAi ? 'on-device translation' : 'translation';

  context.setProgressPercent(initialPercent);
  context.setStatusText(
    initialPercent > 0
      ? `Starting ${runtimeLabel}... (${initialPercent}%)`
      : `Starting ${runtimeLabel}...`
  );

  for (let index = 0; index < normalizedJobs.length; index += 1) {
    const job = normalizedJobs[index];

    if (!job || job.status === 'already_exists' || job.status === 'skipped') {
      continue;
    }

    if (isTranslationJobExpired(job)) {
      await handleExpiredBulkJob(job, index, state, context);
      continue;
    }

    await processBulkTranslationJob(
      job,
      index,
      state,
      { activeProvider, useBrowserAi, core },
      context
    );
  }

  await finalizeBulkTranslation(state, batchId, context);
}


