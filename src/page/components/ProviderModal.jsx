/**
 * ProviderModal Component (Page Editor)
 */

import { Modal, ModalHeader, ModalBody, ModalFooter } from '../../components/Modal';
import { ProviderCard } from '../../components/ProviderCard';
import { Button } from '../../components/Button';
import { __ } from '@wordpress/i18n';

export function ProviderModal({
  isOpen,
  onClose,
  providers = [],
  selectedProvider = '',
  onSelectProvider,
  onStartTranslation,
  onViewError,
}) {
  if (!isOpen) return null;

  const hasAvailable = providers.some((p) => p.isAvailable);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      className="ts-provider-modal-container"
      maxWidth="530px"
      header={
        <ModalHeader
          title={ __( 'Choose Translation Provider', 'translatesense' ) }
          subtitle={ __( 'Choose a translation provider', 'translatesense' ) }
          onClose={onClose}
        />
      }
      footer={
        <ModalFooter align="right">
          <Button variant="secondary" onClick={onClose}>
            { __( 'Cancel', 'translatesense' ) }
          </Button>
          <Button
            variant={hasAvailable ? 'primary' : 'disabled'}
            disabled={!hasAvailable || !selectedProvider}
            onClick={onStartTranslation}
          >
            { __( 'Start Translation', 'translatesense' ) }
          </Button>
        </ModalFooter>
      }
    >
      <div className="ts-provider-list">
        {providers.map((p) => (
          <ProviderCard
            key={p.id}
            provider={p}
            isSelected={selectedProvider === p.id}
            onSelect={onSelectProvider}
            onViewError={onViewError}
          />
        ))}
      </div>
    </Modal>
  );
}

