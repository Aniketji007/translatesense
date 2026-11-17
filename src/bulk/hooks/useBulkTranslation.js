import { useState, useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import { getEvaluatedProviders } from '../../services/providers/providerService';
import { startBulkTranslation, skipBulkPost, retryFailedBatch } from '../services/bulkService';
import { getActiveBatches, getBatchStatus } from '../services/batchService';
import { parseHideReviewNotice } from '../../services/api/reviewService';
import { getPostProgress, getOverallProgress, calculateBatchStats } from '../utils/bulkCalculations';
import { useElapsedTimer } from './useElapsedTimer';
import { runBulkTranslationWorkflow } from '../workflows/bulkTranslationWorkflow';
import CapitalizeText from '../../components/CapitalizedText';

export function useBulkTranslation(initialPostIds = [], options = {}) {
  const isPreviouslyRunning = Boolean(options.isPreviouslyRunning);
  const targetBatchId = options.batchId || null;
  const bulkConfig =
    (typeof window !== 'undefined' && window.TranslateSenseBulkData) || {};
  const itemType =
    options.type === 'term' || options.type === 'media' || options.type === 'post'
      ? options.type
      : (bulkConfig.type === 'term' ? 'term' : bulkConfig.type === 'media' ? 'media' : 'post');
  const subtype =
    options.subtype !== undefined ? options.subtype : (bulkConfig.subtype || '');
  const isTaxonomy = itemType === 'term';
  const isMedia = itemType === 'media';
  const isStandaloneRuntime = isTaxonomy || isMedia;
  const rawSingular =
    bulkConfig.itemSingular || (isTaxonomy ? 'term' : isMedia ? 'media item' : 'post');
  const itemTitlePrefix = rawSingular.charAt(0).toUpperCase() + rawSingular.slice(1);

  const [currentStep, setCurrentStep] = useState(isPreviouslyRunning ? 3 : 1);
  const [postIds, setPostIds] = useState(initialPostIds);

  const [selectedLanguages, setSelectedLanguages] = useState([]);
  const [selectedProvider, setSelectedProvider] = useState('openai');
  const [providers, setProviders] = useState([]);

  const [activeBatches, setActiveBatches] = useState([]);
  const [currentBatchId, setCurrentBatchId] = useState(targetBatchId);
  const [currentBatch, setCurrentBatch] = useState(null);
  const currentBatchIdRef = useRef(targetBatchId);

  useEffect(() => {
    currentBatchIdRef.current = currentBatchId;
  }, [currentBatchId]);

  const [postResultsMap, setPostResultsMap] = useState({});
  const [orderedPostIds, setOrderedPostIds] = useState([]);

  const [isTranslating, setIsTranslating] = useState(false);
  const [isCompleted, setIsCompleted] = useState(false);
  const [progressPercent, setProgressPercent] = useState(0);
  const [statusText, setStatusText] = useState('Initializing...');
  const [totalJobsCount, setTotalJobsCount] = useState(0);

  const [statsSummary, setStatsSummary] = useState(null);
  const [statusFilters, setStatusFilters] = useState([]);
  const [hideReviewNotice, setHideReviewNotice] = useState(true);

  const handleDismissReviewNotice = useCallback(() => {
    setHideReviewNotice(true);
  }, []);

  // Modal progress session context: separates new user queue from existing background queue
  const [progressSessionType, setProgressSessionType] = useState('new_queue'); // 'new_queue' | 'existing_queue'
  const [progressModalSource, setProgressModalSource] = useState('provider_selection'); // 'provider_selection' | 'bulk_button' | 'language_selection_view_progress'

  // Loading and batch states
  const [endpointLoading, setEndpointLoading] = useState(null); // null | 'start_bulk' | 'active_batches'
  const [emptyBatchesState, setEmptyBatchesState] = useState(false);

  // Modals
  const [skipModal, setSkipModal] = useState({ isOpen: false, postId: null, batchId: null });
  const [errorModal, setErrorModal] = useState({
    isOpen: false,
    title: '',
    message: '',
    errorObj: null,
    reasonHTML: '',
    actionUrl: '',
    actionLabel: '',
  });

  // Timer
  const { formattedTimer, humanReadable, startTimer, stopTimer, resetTimer } =
    useElapsedTimer(isTranslating);

  const pollTimerRef = useRef(null);
  const isPollingRef = useRef(false);

  // Runtime browser detection: taxonomy/media runtime sessions and Chrome AI require keeping the window open
  const isQueueChromeAi = useMemo(() => {
    if (progressSessionType === 'new_queue') {
      return selectedProvider === 'chrome_ai' || isStandaloneRuntime;
    }

    const activeBatch = currentBatch || (activeBatches && activeBatches.find((b) => b.batch_id === currentBatchId));
    if (activeBatch) {
      if (activeBatch.provider === 'chrome_ai' || activeBatch.provider === 'chrome_local_ai') {
        return true;
      }
      if (Array.isArray(activeBatch.items)) {
        const hasActiveChrome = activeBatch.items.some((item) => {
          const isChrome = item.provider === 'chrome_ai' || item.provider === 'chrome_local_ai';
          const isActive = item.status === 'pending' || item.status === 'running' || item.status === 'processing';
          return isChrome && isActive;
        });
        if (hasActiveChrome) return true;
      }
    }

    for (const post of Object.values(postResultsMap || {})) {
      if (post.provider === 'chrome_ai' || post.provider === 'chrome_local_ai') {
        const hasActiveTrans = Object.values(post.translations || {}).some(
          (t) => t.status === 'pending' || t.status === 'running' || t.status === 'processing'
        );
        if (hasActiveTrans) return true;
      }
      for (const t of Object.values(post.translations || {})) {
        const isChrome = t.provider === 'chrome_ai' || t.provider === 'chrome_local_ai';
        const isActive = t.status === 'pending' || t.status === 'running' || t.status === 'processing';
        if (isChrome && isActive) return true;
      }
    }

    return false;
  }, [progressSessionType, selectedProvider, isStandaloneRuntime, currentBatch, activeBatches, currentBatchId, postResultsMap]);


  // Load providers and active background batches (posts only) on mount
  useEffect(() => {
    let mounted = true;

    getEvaluatedProviders('en', selectedLanguages[0] || 'es').then((list) => {
      if (!mounted) return;
      setProviders(list);

      const firstAvail = list.find((p) => p.isAvailable && p.statusMeta.isConnected);
      if (firstAvail) {
        setSelectedProvider(firstAvail.id);
      }
    });

    if (!isStandaloneRuntime) {
      getActiveBatches()
        .then((res) => {
          if (!mounted) return;
          const batches = (res && res.batches) || [];
          setActiveBatches(batches);
          if (batches.length > 0 && !currentBatchId) {
            setCurrentBatchId(batches[0].batch_id);
            setCurrentBatch(batches[0]);
          }
        })
        .catch(() => { });
    }

    return () => {
      mounted = false;
      if (pollTimerRef.current) clearTimeout(pollTimerRef.current);
    };
  }, [isStandaloneRuntime]);

  useEffect(() => {
    if (!postResultsMap || Object.keys(postResultsMap).length === 0) return;
    const stats = calculateBatchStats(Object.values(postResultsMap).flatMap(item => Object.values(item.translations)) || []);
    setStatsSummary(stats);
  }, [postResultsMap])

  // Stop background polling timer
  const stopPolling = useCallback(() => {
    if (pollTimerRef.current) {
      clearTimeout(pollTimerRef.current);
      pollTimerRef.current = null;
    }
    isPollingRef.current = false;
  }, []);

  // Populate map from batch
  const populateMapFromBatch = useCallback((batchData, replace = false) => {
    if (!batchData || !Array.isArray(batchData.items)) return;

    const firstItem = batchData.items[0];
    const batchEditorType = firstItem && firstItem.editor_type ? firstItem.editor_type : '';
    const batchIsTaxonomy = batchEditorType ? batchEditorType === 'taxonomy' : isTaxonomy;
    const batchIsMedia = batchEditorType ? batchEditorType === 'media' : isMedia;
    const prefix =
      batchIsTaxonomy === isTaxonomy && batchIsMedia === isMedia
        ? itemTitlePrefix
        : (batchIsTaxonomy ? 'Term' : batchIsMedia ? 'Media' : 'Post');

    setPostResultsMap((prev) => {
      const updated = replace ? {} : { ...prev };

      batchData.items.forEach((item) => {
        const pId = String(item.post_id);
        const tLang = String(item.target_language);

        if (!updated[pId]) {
          updated[pId] = {
            postId: item.post_id,
            postTitle: item.post_title || `${prefix} #${item.post_id}`,
            provider: item.provider || batchData.provider || 'openai',
            sourceEditUrl: item.source_edit_url || '',
            itemSubtype: item.subtype || '',
            translations: {},
          };
        }
        if (item.post_title) updated[pId].postTitle = item.post_title;
        if (item.source_edit_url) updated[pId].sourceEditUrl = item.source_edit_url;
        if (item.subtype) updated[pId].itemSubtype = item.subtype;

        updated[pId].translations[tLang] = {
          targetLang: item.target_language,
          targetPostId: item.target_post_id,
          targetTitle:
            item.target_post_title ||
            (item.status === 'already_exists' || item.status === 'completed'
              ? item.target_post_title || item.post_title
              : CapitalizeText({ text: item.status }) + ` #${pId}` || 'Processing...'),
          editorType: item.editor_type,
          status: item.status,
          completedStrings: item.completed_strings,
          totalStrings: item.total_strings,
          completedCharacters: item.completed_characters || (item.completed_strings ? item.completed_strings * 45 : 0),
          totalCharacters: item.total_characters || (item.total_strings ? item.total_strings * 45 : 0),
          progressPercent: getPostProgress(item),
          progressStatus: item.progress_status || '',
          editUrl: item.edit_url,
          sourceEditUrl: item.source_edit_url || '',
          viewUrl: item.view_url,
          error: item.error_message,
          provider: item.provider || batchData.provider,
        };
      });

      return updated;
    });

    if (replace) {
      const itemsOrder = [];
      batchData.items.forEach((it) => {
        const pidStr = String(it.post_id);
        if (!itemsOrder.includes(pidStr)) itemsOrder.push(pidStr);
      });
      setOrderedPostIds(itemsOrder);
    }

  }, [isTaxonomy, isMedia, itemTitlePrefix]);

  // Server background polling logic
  const pollStatus = useCallback(
    async (batchIdToPoll) => {
      const bId = batchIdToPoll || currentBatchIdRef.current;
      if (!bId) return;

      if (pollTimerRef.current) {
        clearTimeout(pollTimerRef.current);
        pollTimerRef.current = null;
      }

      if (isPollingRef.current) return;
      isPollingRef.current = true;

      try {
        const startTime = performance.now();
        const data = await getBatchStatus(bId);

        // Discard result if user switched to another batch in the meantime
        if (currentBatchIdRef.current && currentBatchIdRef.current !== bId) {
          return;
        }

        setCurrentBatch(data);
        setCurrentBatchId(bId);
        currentBatchIdRef.current = bId;

        const pct =
          Array.isArray(data.items) && data.items.length > 0
            ? Math.round(getOverallProgress(data.items))
            : Math.round(data.progress_percent || 0);

        setProgressPercent(pct);
        setTotalJobsCount(data.total_jobs || (data.items ? data.items.length : 1));
        setStatusText(data.current_status || `Translating content in background... (${pct}%)`);

        populateMapFromBatch(data, false);

        const isFinished =
          data.overall_status === 'completed' ||
          data.overall_status === 'failed' ||
          data.overall_status === 'skipped' ||
          data.overall_status === 'cancelled';

        const timeTaken = performance.now() - startTime;

        if (timeTaken < 3000) {
          await new Promise((resolve) => setTimeout(resolve, 3000 - timeTaken));
        }

        if (isFinished) {
          setIsTranslating(false);
          setIsCompleted(true);
          stopTimer();

          // Continue polling only while active batches exist; stop immediately if all finished
          try {
            const res = await getActiveBatches();
            const remaining = res.batches || [];
            setActiveBatches(remaining);
            if (remaining.length === 0) {
              // No more active batches: stop polling immediately, do not schedule another request
              return;
            }
          } catch {
            return;
          }
        } else {
          setIsTranslating(true);
          setIsCompleted(false);
          startTimer();
          // Active batches exist: continue polling using 5000ms interval
          pollTimerRef.current = setTimeout(() => pollStatus(bId), 5000);
        }
      } catch (err) {
        console.warn('[TranslateSense] Bulk polling error:', err);
        try {
          const res = await getActiveBatches();
          const remaining = res.batches || [];
          setActiveBatches(remaining);
          if (remaining.length > 0 && remaining.some((b) => b.batch_id === bId)) {
            pollTimerRef.current = setTimeout(() => pollStatus(bId), 5000);
          } else {
            setIsTranslating(false);
            stopTimer();
          }
        } catch {
          setIsTranslating(false);
          stopTimer();
        }
      } finally {
        isPollingRef.current = false;
      }
    },
    [populateMapFromBatch, startTimer, stopTimer]
  );

  // Monitor mode activator: immediately enters Step 3 with populated batch data
  const openBatchMonitor = useCallback(
    async (targetBatchId = null, initialBatchData = null, allBatches = null, contextOptions = {}) => {
      stopPolling();

      const sessionType = contextOptions.sessionType || 'existing_queue';
      const modalSource = contextOptions.modalSource || 'bulk_button';
      setProgressSessionType(sessionType);
      setProgressModalSource(modalSource);
      setStatusFilters([]);

      setCurrentStep(2);

      let bId = targetBatchId || currentBatchIdRef.current;
      let data = initialBatchData;

      if (allBatches && Array.isArray(allBatches)) {
        setActiveBatches(allBatches);
      }

      // If missing batch ID or data, trigger /bulk-active-batches immediately once
      if (!bId || !data) {
        setEndpointLoading('active_batches');
        setEmptyBatchesState(false);
        try {
          const res = await getActiveBatches();
          const batches = res.batches || [];
          setActiveBatches(batches);

          // If the response contains an empty batches array, stop polling immediately.
          // Do not schedule another request when there are no active batches.
          if (batches.length === 0) {
            setEndpointLoading(null);
            setIsTranslating(false);
            setIsCompleted(false);
            setStatsSummary(null);
            stopTimer();
            setStatusText('No active translation batches.');
            setEmptyBatchesState(true);
            return;
          }

          bId = bId || batches[0].batch_id;
          data = data || batches.find((b) => b.batch_id === bId) || batches[0];
        } catch (e) {
          console.warn('[TranslateSense] Failed to fetch active batches on openBatchMonitor:', e);
          setEndpointLoading(null);
          setIsTranslating(false);
          stopTimer();
          setStatusText('Failed to load active translation batches.');
          return;
        } finally {
          setEndpointLoading(null);
        }
      } else {
        setEmptyBatchesState(false);
      }

      if (bId) {
        setCurrentBatchId(bId);
        currentBatchIdRef.current = bId;

        if (data) {
          setCurrentBatch(data);
          // Immediately populate posts and items table
          populateMapFromBatch(data, true);

          const isFinished =
            data.overall_status === 'completed' ||
            data.overall_status === 'failed' ||
            data.overall_status === 'skipped' ||
            data.overall_status === 'cancelled';

          if (isFinished) {
            setIsTranslating(false);
            setIsCompleted(true);
            stopTimer();
            // Completed batch: do not schedule polling
            return;
          } else {
            setIsTranslating(true);
            setIsCompleted(false);
            startTimer();
          }

          const pct =
            Array.isArray(data.items) && data.items.length > 0
              ? Math.round(getOverallProgress(data.items))
              : Math.round(data.progress_percent || 0);

          setProgressPercent(pct);
          setTotalJobsCount(data.total_jobs || (data.items ? data.items.length : 1));
          setStatusText(
            data.current_status || `Translating content in background... (${pct}%)`
          );
        } else {
          setIsTranslating(true);
          setIsCompleted(false);
          startTimer();
        }

        // Start live polling cycle
        pollStatus(bId);
      }
    },
    [populateMapFromBatch, pollStatus, startTimer, stopTimer, stopPolling]
  );

  // Switch between multiple background batches preserving session context
  const switchBatch = useCallback(
    (newBatchId) => {
      if (!newBatchId) return;
      const batchData = activeBatches.find((b) => b.batch_id === newBatchId) || null;
      openBatchMonitor(newBatchId, batchData, activeBatches, {
        sessionType: progressSessionType,
        modalSource: progressModalSource,
      });
    },
    [activeBatches, openBatchMonitor, progressSessionType, progressModalSource]
  );

  // Auto-open monitor if options.isPreviouslyRunning is true
  useEffect(() => {
    if (isPreviouslyRunning) {
      openBatchMonitor(targetBatchId);
    }
  }, [isPreviouslyRunning, targetBatchId, openBatchMonitor]);

  // Updates a single post's target language translation state immutably
  const updatePostTranslation = useCallback((postId, targetLang, updates) => {
    const pId = String(postId);
    const tLang = String(targetLang);

    setPostResultsMap((prev) => {
      const currentPost = prev[pId];
      if (!currentPost || !currentPost.translations || !currentPost.translations[tLang]) {
        return prev;
      }

      return {
        ...prev,
        [pId]: {
          ...currentPost,
          translations: {
            ...currentPost.translations,
            [tLang]: {
              ...currentPost.translations[tLang],
              ...updates,
            },
          },
        },
      };
    });
  }, []);

  // Bulk translation workflow helpers are kept in this file to keep the hook focused.
  const bulkTranslationContext = {
    itemType,
    subtype,
    isTaxonomy,
    isMedia,
    itemTitlePrefix,
    updatePostTranslation,
    populateMapFromBatch,
    stopTimer,
    setTotalJobsCount,
    setProgressPercent,
    setStatusText,
    setIsTranslating,
    setIsCompleted,
  };

  const runBulkTranslation = useCallback(
    async (jobsList, batchId, providerOverride = null) => {
      await runBulkTranslationWorkflow(
        jobsList,
        batchId,
        providerOverride || selectedProvider || 'chrome_ai',
        bulkTranslationContext
      );
    },
    [
      selectedProvider,
      itemType,
      subtype,
      isTaxonomy,
      isMedia,
      itemTitlePrefix,
      updatePostTranslation,
      populateMapFromBatch,
      stopTimer,
    ]
  );

  // Start translation trigger from Step 2
  const startTranslation = useCallback(async () => {
    setProgressSessionType('new_queue');
    setProgressModalSource('provider_selection');
    setCurrentStep(2);
    setEndpointLoading('start_bulk');
    setEmptyBatchesState(false);
    setIsTranslating(true);
    setIsCompleted(false);
    setCurrentBatch(null);
    setStatsSummary(null);
    setStatusFilters([]);
    setHideReviewNotice(true);
    setProgressPercent(0);
    resetTimer();

    const isChromeAi = selectedProvider === 'chrome_ai';

    // Initialize map
    const initialMap = {};
    postIds.forEach((pid) => {
      const pidStr = String(pid);
      initialMap[pidStr] = {
        postId: pid,
        postTitle: `${itemTitlePrefix} #${pid}`,
        provider: selectedProvider,
        translations: {},
      };
      selectedLanguages.forEach((lang) => {
        initialMap[pidStr].translations[lang] = {
          targetLang: lang,
          targetPostId: null,
          targetTitle: '',
          status: 'pending',
          completedStrings: 0,
          totalStrings: 0,
          editUrl: '',
          viewUrl: '',
          error: '',
          provider: selectedProvider,
        };
      });
    });

    setPostResultsMap(initialMap);
    setOrderedPostIds(postIds.map(String));
    setTotalJobsCount(postIds.length * selectedLanguages.length);

    try {
      const batchRes = await startBulkTranslation({
        post_ids: postIds,
        target_languages: selectedLanguages,
        provider: selectedProvider,
        type: itemType,
        subtype,
      });

      setEndpointLoading(null);
      setHideReviewNotice(parseHideReviewNotice(batchRes));

      setCurrentBatchId(batchRes.batch_id);

      const batchJobs = Array.isArray(batchRes.jobs) ? batchRes.jobs : [];

      if (!batchJobs || !batchJobs.length) {
        setEndpointLoading(null);
        setHideReviewNotice(true);
        setIsTranslating(false);
        setPostResultsMap({})
        setStatusText(`Error: No job found`);
        setErrorModal({
          isOpen: true,
          title: 'No job found',
          message: `No job found to translate ${TranslateSenseBulkData?.itemSingular} in selected languages.`,
        });
        return;
      }

      populateMapFromBatch(
        {
          subtype: batchRes.subtype || subtype,
          provider: selectedProvider,
          items: batchJobs,
        },
        true
      );
      setTotalJobsCount(batchJobs.length);
      setProgressPercent(Math.round(getOverallProgress(batchJobs)));

      if (isChromeAi || isStandaloneRuntime) {
        runBulkTranslation(batchJobs, batchRes.batch_id, selectedProvider);
      } else {
        const hasQueueJobs = batchJobs.some((job) => job.status !== 'already_exists' && job.status !== 'skipped');

        if (hasQueueJobs) {
          pollStatus(batchRes.batch_id);
        } else {
          setIsTranslating(false);
          setIsCompleted(true);
          stopTimer();
          setStatusText('Translation completed!');
        }
      }
    } catch (err) {
      setEndpointLoading(null);
      setHideReviewNotice(true);
      setIsTranslating(false);
      setStatusText(`Error: ${err.message}`);
      setErrorModal({
        isOpen: true,
        title: 'Batch Creation Error',
        message: err.message,
      });
    }
  }, [
    postIds,
    selectedLanguages,
    selectedProvider,
    itemType,
    subtype,
    isStandaloneRuntime,
    itemTitlePrefix,
    populateMapFromBatch,
    resetTimer,
    runBulkTranslation,
    pollStatus,
  ]);

  // Handle Skip Action
  const handleSkipPost = useCallback(
    async (postId, batchId) => {
      setSkipModal({ isOpen: false, postId: null, batchId: null });
      try {
        await skipBulkPost(batchId || currentBatchId, postId);
        pollStatus(batchId || currentBatchId);
      } catch (err) {
        setErrorModal({
          isOpen: true,
          title: 'Skip Failed',
          message: err.message || 'Could not skip translations.',
        });
      }
    },
    [currentBatchId, pollStatus]
  );

  // Handle Retry Failed Translations Action
  const retryFailedTranslations = useCallback(async () => {
    const bId = currentBatchId || currentBatchIdRef.current;
    if (!bId) return;

    try {
      setIsTranslating(true);
      setIsCompleted(false);
      setStatusText('Restarting failed translations...');
      startTimer(); // Resumes and merges elapsed timer from where it left off

      const res = await retryFailedBatch(bId);

      if (!res.success) {
        setIsTranslating(false);
        stopTimer();
        setStatusText(res.message || 'Failed to retry translations.');
        return;
      }

      if (res.batch) {
        setCurrentBatch(res.batch);
        populateMapFromBatch(res.batch, false);

        const pct =
          Array.isArray(res.batch.items) && res.batch.items.length > 0
            ? Math.round(getOverallProgress(res.batch.items))
            : Math.round(res.batch.progress_percent || 0);

        setProgressPercent(pct);
        setTotalJobsCount(res.batch.total_jobs || (res.batch.items ? res.batch.items.length : 1));
        setStatusText(`Restarted ${res.retried_count} translation job(s)...`);
      }

      // If Chrome AI, taxonomy, or media runtime, execute retried jobs sequentially in browser
      if (isQueueChromeAi && Array.isArray(res.retried_jobs) && res.retried_jobs.length > 0) {
        const retryProvider = (res.batch && res.batch.provider) || selectedProvider;
        runBulkTranslation(res.retried_jobs, bId, retryProvider);
      } else {
        // Backend provider (OpenAI / Gemini): resume live polling
        pollStatus(bId);
      }
    } catch (err) {
      console.error('[TranslateSense] Retry failed batch error:', err);
      setIsTranslating(false);
      stopTimer();
      setStatusText(`Retry failed: ${err.message}`);
    }
  }, [
    currentBatchId,
    startTimer,
    stopTimer,
    populateMapFromBatch,
    isQueueChromeAi,
    runBulkTranslation,
    selectedProvider,
    pollStatus,
  ]);

  return {
    currentStep,
    setCurrentStep,
    postIds,
    setPostIds,
    selectedLanguages,
    setSelectedLanguages,
    selectedProvider,
    setSelectedProvider,
    providers,
    activeBatches,
    currentBatchId,
    setCurrentBatchId,
    currentBatch,
    postResultsMap,
    orderedPostIds,
    isTranslating,
    isCompleted,
    progressPercent,
    statusText,
    totalJobsCount,
    formattedTimer,
    humanReadableTimer: humanReadable,
    statsSummary,
    statusFilters,
    setStatusFilters,
    startTranslation,
    skipModal,
    setSkipModal,
    errorModal,
    setErrorModal,
    handleSkipPost,
    pollStatus,
    openBatchMonitor,
    switchBatch,
    stopPolling,
    populateMapFromBatch,
    setActiveBatches,
    progressSessionType,
    setProgressSessionType,
    progressModalSource,
    setProgressModalSource,
    isQueueChromeAi,
    hideReviewNotice,
    handleDismissReviewNotice,
    endpointLoading,
    setEndpointLoading,
    emptyBatchesState,
    setEmptyBatchesState,
    retryFailedTranslations,
  };
}

