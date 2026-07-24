import unittest
from ai_seller.engine import classify_attachment, decide_stage, extract_facts


class EngineTests(unittest.TestCase):
    def test_request_moves_to_needs(self):
        state = extract_facts('Здравствуйте, хочу панно в гостиную', {})
        self.assertEqual(decide_stage(state, []), 'needs')

    def test_price_first_moves_to_calc_when_size_known(self):
        state = extract_facts('Сколько стоит панно 2 на 1 м?', {})
        self.assertEqual(decide_stage(state, []), 'calc')

    def test_object_photo_moves_to_visual(self):
        state = extract_facts('Пришлю фото стены', {})
        attachment = {'name': 'IMG_1001.jpg'}
        attachment['kind'] = classify_attachment(attachment['name'], '', state)
        self.assertEqual(decide_stage(state, [attachment]), 'visual')

    def test_unknown_image_does_not_move_to_visual(self):
        state = extract_facts('Вот картинка', {})
        attachment = {'name': 'image.jpg'}
        attachment['kind'] = classify_attachment(attachment['name'], '', state)
        self.assertEqual(attachment['kind'], 'unknown')
        self.assertNotEqual(decide_stage(state, [attachment]), 'visual')


if __name__ == '__main__':
    unittest.main()
