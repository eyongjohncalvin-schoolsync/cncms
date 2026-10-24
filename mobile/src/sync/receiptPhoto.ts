import { ImageManipulator, SaveFormat, type ImageRef } from 'expo-image-manipulator';
import { RECEIPT_PHOTO_COMPRESS, receiptPhotoResize } from '../utils/syncTuning';

/**
 * Shrinks a just-captured receipt photo (long edge 1280 px, JPEG ~0.6 —
 * roughly 150–300 KB instead of a 1.5–5 MB camera original) so the file
 * stored in the outbox is already small enough to upload over 3G and to fit
 * the server's upload limit. Called right after capture, before the URI is
 * saved anywhere, by both Record Payment and Request a bon.
 *
 * Never throws: on any failure the original URI is returned, so a photo is
 * never lost just because it could not be shrunk.
 */
export async function shrinkReceiptPhoto(uri: string): Promise<string> {
    const context = ImageManipulator.manipulate(uri);
    const refs: ImageRef[] = [];

    try {
        // Measure the decoded image rather than trusting the picker's
        // width/height, so EXIF rotation can't make us resize the wrong edge.
        let image = await context.renderAsync();
        refs.push(image);

        const resize = receiptPhotoResize(image.width, image.height);

        if (resize) {
            // Free the full-size bitmap before decoding the smaller one.
            image.release();
            refs.pop();
            image = await context.resize(resize).renderAsync();
            refs.push(image);
        }

        const saved = await image.saveAsync({ compress: RECEIPT_PHOTO_COMPRESS, format: SaveFormat.JPEG });

        return saved.uri;
    } catch (error) {
        console.warn('Could not shrink receipt photo; keeping the original.', error);

        return uri;
    } finally {
        refs.forEach((ref) => ref.release());
        context.release();
    }
}
